<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;

/** Explicit resident commands. Bill QR commands reserve amounts, never payment status. */
final class LineBotService
{
    public function __construct(private readonly Application $app,private readonly int $oaId=0) {}

    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? '';
        $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u',
            static fn(array $match): string => chr(mb_ord($match[0], 'UTF-8') - 0xFEE0), $text) ?? '';
        return trim(str_replace("\u{3000}", ' ', $text));
    }

    public static function intent(string $text): ?string
    {
        $text = strtolower(self::normalize($text));
        if ($text === 'slip_image_review_only' || str_starts_with($text, 'แจ้งชำระ ')) return 'slip_guidance';
        if (str_starts_with($text, 'owner-') || str_starts_with($text, 'admin-')) return 'admin_claim';
        if (str_starts_with($text, 'bind-')) return 'bind';
        return match ($text) {
            'help', 'ช่วย', 'เริ่ม', 'start', 'menu', 'เมนู' => 'help',
            'สถานะ', 'status', 'ห้อง', 'room' => 'status',
            'บิล', 'bill', 'bills', 'invoice' => 'bills',
            default => null,
        };
    }

    public function portalUrl(string $section = 'profile'): string
    {
        $base = rtrim((string) $this->app->config->get('APP_URL', ''), '/');
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('A valid application URL is required for LINE links');
        }
        return $base . '/resident#' . ($section === 'bills' ? 'bills' : 'profile');
    }

    public function instructions(): string
    {
        return "รับบิลและตรวจสถานะห้องผ่าน LINE\n"
            . "1. เข้าหน้าโปรไฟล์ผู้พักแล้วกด “สร้างรหัสผูก LINE” หรือขอรหัสจากผู้ดูแล\n"
            . "2. กด “เปิด LINE พร้อมรหัส” แล้วกดส่งข้อความ BIND-… ในแชตส่วนตัวนี้\n"
            . "3. รอข้อความยืนยันชื่อและห้องจากบอท\n"
            . "รหัสจากโปรไฟล์ใช้ได้ 10 นาที ส่วนรหัสจากผู้ดูแลให้ดูวันหมดอายุที่ระบบแสดง ห้ามส่งให้บุคคลอื่น\n"
            . $this->portalUrl() . "\n\n"
            . "คำสั่ง: เมนู / สถานะ / บิล";
    }

    /**
     * Private replies carry the identity needed for a final locked recheck.
     * An expected resident prevents a reply prepared for an old binding from
     * switching to another resident if the LINE account is rebound meanwhile.
     * @return array{text:string,outcome:string,private_resident_id?:int,private_intent?:string,messages?:list<array<string,mixed>>}
     */
    public function command(string $lineUserId, string $intent, ?int $expectedResidentId = null, bool $withPaymentCards = true, ?int $deadlineNanoseconds = null): array
    {
        if ($intent === 'help') {
            return ['text' => "เมนูผู้พัก\n"
                . "• สถานะ หรือ status — ตรวจการผูกบัญชีและสถานะห้อง\n"
                . "• บิล หรือ bills — ดูบิลล่าสุดและสถานะชำระเงิน\n"
                . "• BIND-… — ผูกบัญชีด้วยรหัสที่ระบบสร้าง\n"
                . "ดูบิลและ QR ยอดโอนที่ล็อกไว้: " . $this->portalUrl('bills') . "\n"
                . "หากเว็บแนบสลิปไม่ได้ ส่งเลขบิลและรูปสลิปในแชตนี้ให้ผู้ดูแลตรวจ ไม่ต้องโอนซ้ำ\n"
                . "เปลี่ยนหรือยกเลิกการผูก LINE: " . $this->portalUrl(), 'outcome' => 'help'];
        }
        if (!in_array($intent, ['status', 'bills', 'bound'], true)) {
            throw new \InvalidArgumentException('Unsupported LINE command');
        }
        $resident = $this->boundResident($lineUserId, $expectedResidentId);
        if ($resident === null) {
            return ['text' => "ยังไม่พบการผูก LINE กับผู้พักที่กำลังเข้าพัก\n" . $this->instructions(),
                'outcome' => 'unbound'];
        }
        $name = self::label($resident['full_name']);
        $room = self::label($resident['room_code']);
        $private = ['private_resident_id' => (int) $resident['id'], 'private_intent' => $intent];
        if ($intent === 'bound') {
            return ['text' => "ผูกบัญชี LINE สำเร็จ\nผู้พัก: {$name}\nห้อง: {$room}\n"
                . "คุณจะได้รับบิลของห้องผ่านบัญชีนี้\n"
                . "พิมพ์ “สถานะ” เพื่อตรวจการผูก หรือ “บิล” เพื่อดูบิลล่าสุด\n"
                . $this->portalUrl('bills'), 'outcome' => 'bound'] + $private;
        }
        if ($intent === 'status') {
            return ['text' => "ยืนยันการผูก LINE แล้ว\nผู้พัก: {$name}\nห้อง: {$room}\n"
                . "สถานะห้อง: มีผู้พัก\n"
                . "พิมพ์ “บิล” เพื่อตรวจยอดและสถานะการชำระ ค่าน้ำ/ไฟให้ดูตามรอบในบิล\n"
                . $this->portalUrl('bills'), 'outcome' => 'status'] + $private;
        }

        $statement = $this->app->database()->pdo()->prepare(
            "SELECT b.id,b.bill_no,b.period,b.due_date,b.room_code_snapshot,b.total_amount,b.status,
                    (SELECT p.status FROM payments p WHERE p.bill_id=b.id ORDER BY p.id DESC LIMIT 1) AS payment_status
               FROM bills b WHERE b.resident_id=? ORDER BY b.period DESC,b.id DESC LIMIT 3"
        );
        $statement->execute([(int) $resident['id']]);
        $bills = $statement->fetchAll();
        if ($bills === []) {
            return ['text' => "ผู้พัก: {$name}\nห้อง: {$room}\nยังไม่มีบิลในระบบ\n"
                . "เมื่อผู้ดูแลออกบิลแล้ว จะดูได้ที่\n" . $this->portalUrl('bills'), 'outcome' => 'bills_empty'] + $private;
        }
        $today = (new \DateTimeImmutable('today', new \DateTimeZone(
            (string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'))))->format('Y-m-d');
        $reply = ['text' => self::billsText($name, $bills, $today, $this->portalUrl('bills')), 'outcome' => 'bills'] + $private;
        if ($withPaymentCards) {
            $target = $this->app->lineBills()->recipient((int)$resident['id'], $this->oaId, $lineUserId);
            $messages = [['type'=>'text','text'=>$reply['text']]];
            if ($target !== null) foreach ($bills as $bill) {
                if ($bill['status'] !== 'pending' || in_array($bill['payment_status'], ['pending','verified'], true)) continue;
                if ($deadlineNanoseconds !== null && $deadlineNanoseconds - hrtime(true) < 2_500_000_000) break;
                $card = $this->app->lineBills()->paymentCard((int)$bill['id'], (int)$resident['id'], $target, $deadlineNanoseconds);
                if ($card !== null) $messages[] = $card;
            }
            $reply['messages'] = $messages;
        }
        return $reply;
    }

    /** @return array<string,mixed>|null */
    private function boundResident(string $lineUserId, ?int $expectedResidentId = null): ?array
    {
        if (preg_match('/^U[0-9a-f]{32}$/D', $lineUserId) !== 1) return null;
        $lookup=$this->app->database()->pdo()->prepare("SELECT id FROM line_room_bindings WHERE oa_id=? AND line_user_id=? AND status='bound' LIMIT 1");
        $lookup->execute([$this->oaId,$lineUserId]);$bindingId=$lookup->fetchColumn();
        if($bindingId!==false){
            $binding=$this->app->lineRoomBindings()->verified((int)$bindingId,$lineUserId,$this->oaId);
            if($binding!==null&&($expectedResidentId===null||$expectedResidentId===(int)$binding['resident_id'])){
                $profile=$this->app->database()->pdo()->prepare('SELECT r.id,r.full_name,rm.room_code FROM residents r JOIN occupancies o ON o.resident_id=r.id JOIN rooms rm ON rm.id=o.room_id WHERE r.id=? AND o.id=?');
                $profile->execute([$binding['resident_id'],$binding['occupancy_id']]);return $profile->fetch()?:null;
            }
        }
        if($this->oaId!==0)return null;
        $statement = $this->app->database()->pdo()->prepare(
            "SELECT r.id,r.full_name,rm.room_code
               FROM residents r
               JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
               JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
              WHERE r.line_user_id=? AND r.active=1 ORDER BY o.id DESC LIMIT 2"
        );
        $statement->execute([$lineUserId]);
        $rows = $statement->fetchAll();
        if (count($rows) !== 1
            || ($expectedResidentId !== null && (int) $rows[0]['id'] !== $expectedResidentId)
            || !$this->app->notifications()->isLineBindingVerified((int) $rows[0]['id'], $lineUserId)
            || $this->app->lineRoomBindings()->isBlocked((int)$rows[0]['id'])) {
            return null;
        }
        return $rows[0];
    }

    /** @param list<array<string,mixed>> $bills */
    public static function billsText(string $name, array $bills, string $today, string $url): string
    {
        $lines = ['บิลล่าสุดของ ' . self::label($name)];
        $mayPay = false;
        foreach (array_slice($bills, 0, 3) as $bill) {
            $payment = $bill['payment_status'] ?? null;
            if (($bill['status'] ?? null) === 'pending' && $payment === null) $mayPay = true;
            $status = ($bill['status'] ?? null) === 'paid' ? 'ชำระแล้ว — ไม่ต้องโอนซ้ำ'
                : (in_array($payment, ['pending', 'verified'], true) ? 'กำลังตรวจสอบการชำระ — อย่าโอนซ้ำ'
                    : (($bill['due_date'] ?? '') < $today ? 'เกินกำหนดชำระ' : 'รอชำระ'));
            $lines[] = "\n" . self::label($bill['bill_no']) . ' · ห้อง ' . self::label($bill['room_code_snapshot']);
            $lines[] = 'รอบ ' . substr((string) $bill['period'], 0, 7) . ' · ' . self::money((string) $bill['total_amount']) . ' บาท';
            $lines[] = 'ครบกำหนด ' . self::label($bill['due_date']) . ' · ' . $status;
            if ($payment === 'rejected' && ($bill['status'] ?? null) !== 'paid') {
                $lines[] = 'สลิปไม่ผ่าน หากโอนแล้วให้ติดต่อผู้ดูแลเพื่อตรวจยอดก่อนโอนซ้ำ';
            }
        }
        $lines[] = ($mayPay
            ? "\nสำหรับบิลรอชำระที่ยังไม่ได้โอน เปิดบิลเพื่อดู QR และยอดโอนที่ล็อกไว้ (ยอดบิล + 0.01–0.99 บาท) โอนตามยอด QR ห้ามปัดเศษ (ต้องเข้าสู่ระบบ):\n"
            : "\nดูรายละเอียดและสถานะบิล (ต้องเข้าสู่ระบบ):\n") . $url;
        $lines[] = 'หากแนบสลิปในเว็บไม่ได้ ส่งเลขบิลและรูปสลิปในแชตนี้ให้ผู้ดูแลตรวจ การส่งรูปยังไม่ยืนยันว่าชำระแล้ว หากโอนแล้วไม่ต้องโอนซ้ำ';
        return implode("\n", $lines);
    }

    private static function label(mixed $value): string
    {
        return mb_substr(trim(preg_replace('/[\p{C}\s]+/u', ' ', (string) $value) ?? ''), 0, 150);
    }

    private static function money(string $amount): string
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $amount, $match)) {
            throw new \RuntimeException('Invalid stored bill amount');
        }
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $match[1])
            . '.' . str_pad($match[2] ?? '', 2, '0');
    }
}
