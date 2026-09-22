<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\QrPng;
use Dormitory\Support\Validator;

/** One bill/amount registry for browser QR, LINE push and LINE replies. */
final class LineBillService
{
    private const TTL = 30 * 86400;
    private const IMAGE_PATH = '/api/public/line-payment-qr/';

    public function __construct(private readonly Application $app) {}

    /** Only images are bearer-accessible; bill details still require resident login. */
    private function imageBase(): ?string
    {
        $base = rtrim((string)$this->app->config->get('APP_URL', ''), '/');
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !function_exists('imagepng')) return null;
        return $base;
    }

    /** @return array<string,mixed>|null An authorized recipient of this exact OA. */
    public function recipient(int $residentId, int $oaId, string $lineUserId): ?array
    {
        foreach ($this->app->notifications()->recipients($residentId) as $target) {
            if ((int)$target['oa_id'] === $oaId && hash_equals((string)$target['line_user_id'], $lineUserId)) return $target;
        }
        return null;
    }

    /** @return array<string,mixed>|null Safe text-only fallback for expected configuration blockers. */
    public function paymentCard(int $billId, int $residentId, array $target, ?int $deadlineNanoseconds = null): ?array
    {
        $base = $this->imageBase();
        if ($base === null) return null;
        try {
            $instruction = $this->reserve($billId, $residentId, $deadlineNanoseconds);
            if ($instruction === null) return null;
            $q = $this->app->database()->pdo()->prepare('SELECT auth_version FROM residents WHERE id=? AND active=1');
            $q->execute([$residentId]);
            $version = $q->fetchColumn();
            if ($version === false) return null;
            $parts = ['v1', $billId, Validator::scaledDecimal($instruction['transfer_amount'],'amount',2,12), $residentId, (int)$version,
                (int)$target['oa_id'], (int)$target['id'], (int)$target['occupancy_id'], time() + self::TTL,
                $this->recipientHash((string)$target['line_user_id'])];
            $unsigned = implode('.', $parts);
            $token = $unsigned . '.' . $this->sign($unsigned);
            $state = $this->readableState($billId, $token);
            return $this->flex($state, $base . self::IMAGE_PATH . $billId . '?token=' . $token);
        } catch (HttpException $error) {
            if (!in_array($error->errorCode, ['PROMPTPAY_NOT_CONFIGURED', 'TRANSFER_TARGET_CHANGED',
                'TRANSFER_SLOTS_FULL', 'TRANSFER_AMOUNT_INVALID', 'BILL_ALREADY_PAID', 'PAYMENT_ALREADY_PENDING',
                'BILL_NOT_FOUND', 'LINE_QR_UNAVAILABLE'], true)) throw $error;
            return null;
        }
    }

    /** A webhook must not inherit MySQL's long lock wait or the normal transaction retries. */
    private function reserve(int $billId, int $residentId, ?int $deadlineNanoseconds): ?array
    {
        if ($deadlineNanoseconds === null) return $this->app->transfers()->reserve($billId, $residentId);
        if ($deadlineNanoseconds - hrtime(true) < 2_500_000_000) return null;
        $pdo = $this->app->database()->pdo();
        if ($pdo->inTransaction()) return null;
        $previous = (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
        try {
            $pdo->beginTransaction();
            $instruction = $this->app->transfers()->reserve($billId, $residentId);
            $pdo->commit();
            return $instruction;
        } catch (\PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [1205,1213], true)) return null;
            throw $error;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $previous);
        }
    }

    /** Read-only: opening an image never reserves, rotates or changes an amount. */
    public function image(int $billId, mixed $token): string
    {
        $state = $this->readableState($billId, $token);
        return QrPng::render(PromptPayService::payload($state['promptpay_target'], $state['transfer_amount']));
    }

    /** Recheck queued QR immediately before sending; never rewrite a retried body. */
    public function assertStoredCard(array $card, int $billId, array $target): void
    {
        $url = $card['contents']['hero']['url'] ?? null;
        $base = $this->imageBase();
        $prefix = $base . self::IMAGE_PATH . $billId . '?token=';
        if ($base === null || !is_string($url) || !str_starts_with($url, $prefix)) $this->unavailable();
        $token = substr($url, strlen($prefix));
        $claims = $this->claims($billId, $token);
        if ((int)$claims[5] !== (int)$target['oa_id'] || (int)$claims[6] !== (int)$target['id']
            || !hash_equals($claims[9], $this->recipientHash((string)$target['line_user_id']))) $this->unavailable();
        $state = $this->readableState($billId, $token);
        // MySQL JSON normalizes object-key order; list order and scalar types stay strict.
        if (self::canonical($card) !== self::canonical($this->flex($state, $url))) $this->unavailable();
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonical($item);
        return $value;
    }

    /** @return list<string> */
    private function claims(int $billId, mixed $token): array
    {
        if (!is_string($token) || strlen($token) > 400
            || !preg_match('/^v1(?:\.(?:0|[1-9][0-9]{0,14})){8}\.[a-f0-9]{64}\.[a-f0-9]{64}$/D', $token)) $this->unavailable();
        $parts = explode('.', $token);
        $signature = array_pop($parts);
        if (!hash_equals($this->sign(implode('.', $parts)), $signature)
            || (int)$parts[1] !== $billId || $billId < 1
            || (int)$parts[8] <= time() || (int)$parts[8] > time() + self::TTL + 60) $this->unavailable();
        foreach ([2, 3, 4, 7] as $index) if ((int)$parts[$index] < 1) $this->unavailable();
        return $parts;
    }

    private function sign(string $unsigned): string
    {
        return hash_hmac('sha256', "line-payment-image-v1\n" . $unsigned, $this->app->config->appKey());
    }

    private function recipientHash(string $lineUserId): string
    {
        return hash_hmac('sha256', "line-payment-recipient\n" . $lineUserId, $this->app->config->appKey());
    }

    /** @return array<string,mixed> Uniform denial prevents bill/recipient enumeration. */
    private function readableState(int $billId, mixed $token): array
    {
        $claims = $this->claims($billId, $token);
        $q = $this->app->database()->pdo()->prepare("SELECT b.id,b.resident_id,b.bill_no,b.period,b.due_date,
                b.room_code_snapshot AS room_code,b.total_amount,t.bill_amount,
                t.transfer_amount,t.promptpay_target,t.recipient_name,r.auth_version,o.id AS occupancy_id
            FROM bills b JOIN transfer_instructions t ON t.bill_id=b.id AND t.resident_id=b.resident_id
            JOIN residents r ON r.id=b.resident_id AND r.active=1
            JOIN occupancies o ON o.id=b.occupancy_id AND o.resident_id=r.id AND o.status='active'
            JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            JOIN integration_settings s ON s.id=1 AND BINARY s.promptpay_target=BINARY t.promptpay_target
            WHERE b.id=? AND b.status='pending' AND t.status='reserved' AND b.total_amount=t.bill_amount
              AND NOT EXISTS(SELECT 1 FROM payments p WHERE p.bill_id=b.id AND p.status IN ('pending','verified'))");
        $q->execute([$billId]);
        $row = $q->fetch();
        if (!$row || Validator::scaledDecimal($row['transfer_amount'],'amount',2,12) !== (int)$claims[2] || (int)$row['resident_id'] !== (int)$claims[3]
            || (int)$row['auth_version'] !== (int)$claims[4] || (int)$row['occupancy_id'] !== (int)$claims[7]) $this->unavailable();
        $found = false;
        foreach ($this->app->notifications()->recipients((int)$row['resident_id']) as $target) {
            if ((int)$target['oa_id'] === (int)$claims[5] && (int)$target['id'] === (int)$claims[6]
                && (int)$target['occupancy_id'] === (int)$claims[7]
                && hash_equals($claims[9], $this->recipientHash((string)$target['line_user_id']))) $found = true;
        }
        if (!$found) $this->unavailable();
        return $row;
    }

    private function unavailable(): never
    {
        throw new HttpException(404, 'QR นี้ไม่พร้อมใช้ กรุณาเปิดบิลล่าสุด หากโอนแล้วไม่ต้องโอนซ้ำ', 'LINE_QR_UNAVAILABLE');
    }

    /** Deterministic layout permits strict validation of persisted messages. */
    private function flex(array $bill, string $url): array
    {
        $label = static fn(mixed $s): string => mb_substr(trim(preg_replace('/[\p{C}\s]+/u', ' ', (string)$s) ?? ''), 0, 150);
        $row = static fn(string $key, string $value): array => ['type'=>'box','layout'=>'baseline','spacing'=>'sm','contents'=>[
            ['type'=>'text','text'=>$key,'size'=>'sm','color'=>'#555555','flex'=>2],
            ['type'=>'text','text'=>$value === '' ? '-' : $value,'size'=>'sm','wrap'=>true,'flex'=>4],
        ]];
        return ['type'=>'flex','altText'=>'บิล '.$label($bill['bill_no']).' ยอดโอน '.$bill['transfer_amount'].' บาท หากโอนแล้วไม่ต้องโอนซ้ำ',
            'contents'=>['type'=>'bubble','size'=>'mega',
                'hero'=>['type'=>'image','url'=>$url,'size'=>'full','aspectRatio'=>'1:1','aspectMode'=>'fit'],
                'body'=>['type'=>'box','layout'=>'vertical','spacing'=>'sm','contents'=>[
                    ['type'=>'text','text'=>'QR ชำระเงิน','weight'=>'bold','size'=>'lg'],
                    $row('เลขบิล', $label($bill['bill_no'])), $row('ห้อง', $label($bill['room_code'])),
                    $row('รอบบิล', substr((string)$bill['period'],0,7)), $row('ครบกำหนด', (string)$bill['due_date']),
                    $row('ยอดบิล', $bill['bill_amount'].' บาท'), $row('ยอดโอน', $bill['transfer_amount'].' บาท'),
                    $row('ผู้รับ', $label($bill['recipient_name'] ?: 'ตรวจชื่อในแอปธนาคารก่อนยืนยัน')),
                    ['type'=>'text','text'=>'โอนตามยอด QR ห้ามปัดเศษ ตรวจชื่อผู้รับก่อนโอน หากโอนแล้วไม่ต้องโอนซ้ำ','wrap'=>true,'size'=>'sm'],
                    ['type'=>'text','text'=>'แนบสลิปในเว็บไม่ได้ ให้ส่งเลขบิลและรูปสลิปในแชตนี้ให้ผู้ดูแลตรวจ การส่งรูปยังไม่ยืนยันว่าชำระแล้ว','wrap'=>true,'size'=>'xs'],
                    ['type'=>'text','text'=>'รูปมีอายุไม่เกิน 30 วัน หากเปิดไม่ได้ให้พิมพ์ บิล เพื่ออ่านสถานะล่าสุด','wrap'=>true,'size'=>'xs'],
                ]],
                'footer'=>['type'=>'box','layout'=>'vertical','contents'=>[
                    ['type'=>'button','style'=>'primary','action'=>['type'=>'uri','label'=>'เปิดบิล / ส่งสลิป',
                        'uri'=>(new LineBotService($this->app))->portalUrl('bills')]],
                ]],
            ],
        ];
    }
}
