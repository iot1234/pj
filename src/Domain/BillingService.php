<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;
use PDO;

final class BillingService
{
    private const MAX_DECIMAL_14_2_CENTS = 99_999_999_999_999;
    private const PREVIEW_TTL_SECONDS = 300;

    public function __construct(private readonly Application $app)
    {
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $row = $this->app->database()->pdo()->query(
            'SELECT water_rate,electric_rate,due_days,updated_by,updated_at FROM billing_settings WHERE id=1'
        )->fetch();
        if (!$row) {
            return [
                'water_rate' => '0.00', 'electric_rate' => '0.00', 'due_days' => 7,
                'configured' => false, 'updated_at' => null,
            ];
        }
        return $this->mapSettings($row);
    }

    /** @return array<string,mixed> */
    public function updateSettings(array $input, int $adminId): array
    {
        Validator::only($input, ['water_rate', 'electric_rate', 'due_days']);
        $water = Validator::scaledDecimal($input['water_rate'] ?? null, 'water_rate', 2, 7);
        $electric = Validator::scaledDecimal($input['electric_rate'] ?? null, 'electric_rate', 2, 7);
        if($water>100_000_000||$electric>100_000_000)throw new HttpException(422,'Billing rates must not exceed 1,000,000.00','VALIDATION_ERROR');
        $dueDays = filter_var($input['due_days'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if ($dueDays === false) {
            throw new HttpException(422, 'due_days must be between 1 and 60', 'VALIDATION_ERROR', ['field' => 'due_days']);
        }
        $pdo = $this->app->database()->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO billing_settings (id,water_rate,electric_rate,due_days,updated_by,updated_at)
             VALUES (1,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE water_rate=VALUES(water_rate),electric_rate=VALUES(electric_rate),due_days=VALUES(due_days),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
        );
        $statement->execute([
            Validator::decimalString($water), Validator::decimalString($electric), (int) $dueDays, $adminId,
        ]);
        return $this->settings();
    }

    /** @return array<string,mixed> */
    public function preview(array $input): array
    {
        $preview=$this->buildPreview($input, $this->app->database()->pdo(), false);
        $expiresAt=time()+self::PREVIEW_TTL_SECONDS;
        $preview['preview_token']=$this->previewToken($preview,$expiresAt);
        $preview['preview_expires_at']=gmdate('Y-m-d\TH:i:s\Z',$expiresAt);
        return $preview;
    }

    /** @return array<string,mixed> */
    public function bulk(array $input, int $adminId): array
    {
        Validator::only($input, ['period','room_ids','water_rate','electric_rate','other_description','other_amount','due_date','preview_token']);
        $token=Validator::string($input['preview_token']??null,'preview_token',40,2048);
        unset($input['preview_token']);
        return $this->app->database()->transaction(function (PDO $pdo) use ($input, $adminId, $token): array {
            $preview = $this->buildPreview($input, $pdo, true);
            $this->assertPreviewToken($token,$preview);
            if ($preview['issues'] !== []) {
                throw new HttpException(422, 'Some rooms cannot be billed', 'BILL_PREVIEW_INVALID', ['issues' => $preview['issues']]);
            }

            $created = [];
            $skipped = [];
            foreach ($preview['bills'] as $bill) {
                $exists = $pdo->prepare('SELECT id,bill_no FROM bills WHERE occupancy_id=? AND period=? FOR UPDATE');
                $exists->execute([$bill['occupancy_id'], $preview['period'] . '-01']);
                $existing = $exists->fetch();
                if ($existing) {
                    $skipped[] = ['id' => (int) $existing['id'], 'bill_no' => $existing['bill_no'], 'reason' => 'already_exists'];
                    continue;
                }
                $billNo = $this->newBillNumber($preview['period']);
                $insert = $pdo->prepare(
                    'INSERT INTO bills
                     (bill_no,occupancy_id,resident_id,room_id,period,due_date,rent_amount,
                      resident_name_snapshot,room_code_snapshot,
                      water_previous,water_current,water_units,water_rate,water_amount,
                      electric_previous,electric_current,electric_units,electric_rate,electric_amount,
                      other_description,other_amount,total_amount,status,created_by,created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\',?,UTC_TIMESTAMP())'
                );
                $insert->execute([
                    $billNo, $bill['occupancy_id'], $bill['resident_id'], $bill['room_id'], $preview['period'] . '-01', $preview['due_date'],
                    $bill['rent_amount'], $bill['full_name'], $bill['room_code'], $bill['water_previous'], $bill['water_current'], $bill['water_units'], $bill['water_rate'], $bill['water_amount'],
                    $bill['electric_previous'], $bill['electric_current'], $bill['electric_units'], $bill['electric_rate'], $bill['electric_amount'],
                    $bill['other_description'], $bill['other_amount'], $bill['total_amount'], $adminId,
                ]);
                $billId = (int) $pdo->lastInsertId();
                $item = $pdo->prepare('INSERT INTO bill_items (bill_id,item_type,description,quantity,unit_price,amount) VALUES (?,?,?,?,?,?)');
                $item->execute([$billId, 'rent', 'Monthly rent', '1.00', $bill['rent_amount'], $bill['rent_amount']]);
                $item->execute([$billId, 'water', 'Water usage', $bill['water_units'], $bill['water_rate'], $bill['water_amount']]);
                $item->execute([$billId, 'electric', 'Electric usage', $bill['electric_units'], $bill['electric_rate'], $bill['electric_amount']]);
                if ($bill['other_description'] !== null) {
                    $item->execute([$billId, 'other', $bill['other_description'], '1.00', $bill['other_amount'], $bill['other_amount']]);
                }
                $created[] = ['id' => $billId, 'bill_no' => $billNo, 'room_id' => $bill['room_id'], 'total_amount' => $bill['total_amount']];
            }
            return ['period' => $preview['period'], 'created' => $created, 'skipped' => $skipped];
        });
    }

    /** @return list<array<string,mixed>> */
    public function adminList(?string $period): array
    {
        $parameters = [];
        $where = '';
        if ($period !== null && $period !== '') {
            $where = ' WHERE b.period=?';
            $parameters[] = Validator::periodDate($period);
        }
        $statement = $this->app->database()->pdo()->prepare(
            'SELECT b.*,b.room_code_snapshot AS room_code,b.resident_name_snapshot AS full_name,res.phone_norm,
                    res.id AS line_resident_id,res.line_user_id AS line_recipient,
                    n.status AS line_status,n.attempts AS line_attempts,n.last_error AS line_last_error,n.sent_at AS line_sent_at
               FROM bills b
               JOIN rooms r ON r.id=b.room_id
               JOIN residents res ON res.id=b.resident_id
               LEFT JOIN notification_outbox n ON n.bill_id=b.id AND n.purpose=\'bill_delivery\'' . $where .
            ' ORDER BY b.period DESC,r.floor,r.room_code'
        );
        $statement->execute($parameters);
        $rows=$statement->fetchAll();$bindings=[];
        foreach($rows as $row)$bindings[]=['resident_id'=>(int)$row['line_resident_id'],'line_user_id'=>$row['line_recipient']??null];
        $verified=$this->app->notifications()->verifiedLineBindings($bindings);
        foreach($rows as &$row){
            $row['line_linked']=$verified[(int)$row['line_resident_id']]??false;
            unset($row['line_resident_id'],$row['line_recipient']);
        }
        unset($row);
        return array_map($this->mapBill(...), $rows);
    }

    /** @return list<array<string,mixed>> */
    public function residentList(int $residentId): array
    {
        $statement = $this->app->database()->pdo()->prepare(
            'SELECT b.*,b.room_code_snapshot AS room_code FROM bills b WHERE b.resident_id=? ORDER BY b.period DESC,b.id DESC'
        );
        $statement->execute([$residentId]);
        return array_map($this->mapBill(...), $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function residentDetail(int $residentId, int $billId): array
    {
        $statement = $this->app->database()->pdo()->prepare(
            'SELECT b.*,b.room_code_snapshot AS room_code FROM bills b WHERE b.id=? AND b.resident_id=?'
        );
        $statement->execute([$billId, $residentId]);
        $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'Bill not found', 'BILL_NOT_FOUND');
        $bill = $this->mapBill($row);
        $items = $this->app->database()->pdo()->prepare('SELECT item_type,description,quantity,unit_price,amount FROM bill_items WHERE bill_id=? ORDER BY id');
        $items->execute([$billId]);
        $bill['items'] = $items->fetchAll();
        $payment = $this->app->database()->pdo()->prepare('SELECT id,status,rejection_reason,created_at,verified_at FROM payments WHERE bill_id=? ORDER BY id DESC LIMIT 1');
        $payment->execute([$billId]);
        $bill['payment'] = $payment->fetch() ?: null;
        $integrations=$this->app->settings()->publicSettings();
        $bill['payment_capabilities']=[
            'promptpay_ready'=>($integrations['promptpay_ready']??false)===true,
            'slip_verification_ready'=>($integrations['slip_verification_ready']??false)===true,
            'slip_max_bytes'=>(int)($integrations['slip_max_bytes']??4_194_304),
        ];
        return $bill;
    }

    /** @param array<string,mixed> $bill */
    public function assertPromptPayAvailable(array $bill): void
    {
        if (($bill['status'] ?? null) !== 'pending') {
            throw new HttpException(409, 'บิลนี้ชำระแล้ว', 'BILL_ALREADY_PAID');
        }

        $capabilities = is_array($bill['payment_capabilities'] ?? null)
            ? $bill['payment_capabilities']
            : [];
        if (($capabilities['promptpay_ready'] ?? false) !== true) {
            throw new HttpException(503, 'ยังไม่ได้ตั้งค่า PromptPay กรุณาติดต่อผู้ดูแลก่อนโอน', 'PROMPTPAY_NOT_CONFIGURED');
        }
        if (($capabilities['slip_verification_ready'] ?? false) !== true) {
            throw new HttpException(503, 'ระบบตรวจสลิปยังไม่พร้อม จึงยังไม่สามารถสร้าง QR ได้', 'SLIP_NOT_CONFIGURED');
        }

        $payment = is_array($bill['payment'] ?? null) ? $bill['payment'] : [];
        if (in_array($payment['status'] ?? null, ['pending', 'verified'], true)) {
            throw new HttpException(409, 'บิลนี้มีรายการชำระที่กำลังดำเนินการอยู่แล้ว', 'PAYMENT_ALREADY_PENDING');
        }
    }

    /** @return array<string,mixed> */
    private function buildPreview(array $input, PDO $pdo, bool $lock): array
    {
        Validator::only($input, ['period','room_ids','water_rate','electric_rate','other_description','other_amount','due_date']);
        $period = Validator::period($input['period'] ?? null);
        $periodDate = $period . '-01';
        $dueDate = Validator::date($input['due_date'] ?? null, 'due_date');
        if ($dueDate < $periodDate) {
            throw new HttpException(422, 'due_date cannot precede the billing period', 'VALIDATION_ERROR', ['field' => 'due_date']);
        }
        $settings = $this->settings();
        if(($settings['configured']??false)!==true){
            throw new HttpException(409,'กรุณาตรวจสอบและบันทึกค่าน้ำ ค่าไฟ และวันครบกำหนดในหน้า “ตั้งค่า” ก่อนออกบิล','BILLING_SETTINGS_NOT_CONFIRMED');
        }
        $waterRate = Validator::scaledDecimal($input['water_rate'] ?? $settings['water_rate'], 'water_rate', 2, 7);
        $electricRate = Validator::scaledDecimal($input['electric_rate'] ?? $settings['electric_rate'], 'electric_rate', 2, 7);
        if($waterRate>100_000_000||$electricRate>100_000_000)throw new HttpException(422,'Billing rates must not exceed 1,000,000.00','VALIDATION_ERROR');
        $otherAmount = Validator::scaledDecimal($input['other_amount'] ?? '0', 'other_amount', 2, 9);
        $otherDescription = null;
        if ($otherAmount > 0) {
            $otherDescription = Validator::string($input['other_description'] ?? null, 'other_description', 1, 255);
        } elseif (isset($input['other_description']) && trim((string) $input['other_description']) !== '') {
            throw new HttpException(422, 'other_description requires a positive other_amount', 'VALIDATION_ERROR', ['field' => 'other_amount']);
        }
        $roomIds = [];
        if (!array_key_exists('room_ids', $input))throw new HttpException(422,'room_ids is required','VALIDATION_ERROR',['field'=>'room_ids']);
        if (array_key_exists('room_ids', $input)) {
            if (!is_array($input['room_ids']) || count($input['room_ids']) > 500) {
                throw new HttpException(422, 'room_ids must be an array with at most 500 items', 'VALIDATION_ERROR', ['field' => 'room_ids']);
            }
            foreach ($input['room_ids'] as $id) $roomIds[] = Validator::id($id, 'room_ids');
            $roomIds = array_values(array_unique($roomIds));
            if ($roomIds === []) throw new HttpException(422, 'Select at least one room', 'VALIDATION_ERROR', ['field' => 'room_ids']);
        }

        $lastDay = (new \DateTimeImmutable($periodDate))->modify('last day of this month')->format('Y-m-d');
        $parameters = [$lastDay, $periodDate];
        $filter = '';
        if ($roomIds !== []) {
            $filter = ' AND o.room_id IN (' . implode(',', array_fill(0, count($roomIds), '?')) . ')';
            array_push($parameters, ...$roomIds);
        }
        $sql = 'SELECT o.id AS occupancy_id,o.resident_id,o.room_id,o.monthly_rent,r.room_code,res.full_name
                  FROM occupancies o JOIN rooms r ON r.id=o.room_id JOIN residents res ON res.id=o.resident_id
                 WHERE o.move_in_date<=? AND (o.move_out_date IS NULL OR o.move_out_date>=?)' . $filter .
               ' ORDER BY r.floor,r.room_code' . ($lock ? ' FOR UPDATE' : '');
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $occupancies = $statement->fetchAll();

        $bills = [];
        $foundRoomIds=array_map(static fn(array $row):int=>(int)$row['room_id'],$occupancies);
        $partition=self::partitionOccupancies($occupancies);
        $occupancies=$partition['occupancies'];
        $issues=$partition['issues'];
        foreach(array_diff($roomIds,$foundRoomIds)as$missingRoomId)$issues[]=['room_id'=>$missingRoomId,'code'=>'NO_OCCUPANCY'];
        foreach ($occupancies as $occupancy) {
            $meter = $pdo->prepare('SELECT meter_type,previous_reading,current_reading,units_used FROM meter_readings WHERE room_id=? AND period=?' . ($lock ? ' FOR UPDATE' : ''));
            $meter->execute([$occupancy['room_id'], $periodDate]);
            $readings = [];
            foreach ($meter->fetchAll() as $row) $readings[$row['meter_type']] = $row;
            $missing = array_values(array_diff(['water','electric'], array_keys($readings)));
            if ($missing !== []) {
                $issues[] = ['room_id'=>(int)$occupancy['room_id'],'room_code'=>$occupancy['room_code'],'code'=>'MISSING_METER','meter_types'=>$missing];
                continue;
            }
            $rent = Validator::scaledDecimal($occupancy['monthly_rent'], 'monthly_rent', 2, 9);
            $waterUnits = Validator::scaledDecimal($readings['water']['units_used'], 'water_units', 2, 12);
            $electricUnits = Validator::scaledDecimal($readings['electric']['units_used'], 'electric_units', 2, 12);
            $waterAmount = self::multiplyMoney($waterUnits, $waterRate);
            $electricAmount = self::multiplyMoney($electricUnits, $electricRate);
            $total = self::sumMoney($rent, $waterAmount, $electricAmount, $otherAmount);
            $bills[] = [
                'occupancy_id'=>(int)$occupancy['occupancy_id'],'resident_id'=>(int)$occupancy['resident_id'],'room_id'=>(int)$occupancy['room_id'],
                'room_code'=>$occupancy['room_code'],'full_name'=>$occupancy['full_name'],'resident_name'=>$occupancy['full_name'],
                'rent_amount'=>Validator::decimalString($rent),
                'water_previous'=>(string)$readings['water']['previous_reading'],'water_current'=>(string)$readings['water']['current_reading'],
                'water_units'=>(string)$readings['water']['units_used'],'water_rate'=>Validator::decimalString($waterRate),'water_amount'=>Validator::decimalString($waterAmount),
                'electric_previous'=>(string)$readings['electric']['previous_reading'],'electric_current'=>(string)$readings['electric']['current_reading'],
                'electric_units'=>(string)$readings['electric']['units_used'],'electric_rate'=>Validator::decimalString($electricRate),'electric_amount'=>Validator::decimalString($electricAmount),
                'other_description'=>$otherDescription,'other_amount'=>Validator::decimalString($otherAmount),'total_amount'=>Validator::decimalString($total),
            ];
        }
        return ['period'=>$period,'due_date'=>$dueDate,'bills'=>$bills,'issues'=>$issues];
    }

    /**
     * A room meter is monthly, so it cannot safely be assigned to two
     * occupancies that overlap the same period without an explicit proration
     * policy. Exclude such rooms and surface one fail-closed issue per room.
     *
     * @param list<array<string,mixed>> $occupancies
     * @return array{occupancies:list<array<string,mixed>>,issues:list<array<string,mixed>>}
     */
    private static function partitionOccupancies(array $occupancies): array
    {
        $byRoom=[];
        foreach($occupancies as $occupancy)$byRoom[(string)(int)$occupancy['room_id']][]=$occupancy;
        $safe=[];$issues=[];
        foreach($byRoom as $rows){
            if(count($rows)===1){$safe[]=$rows[0];continue;}
            $issues[]=[
                'room_id'=>(int)$rows[0]['room_id'],
                'room_code'=>$rows[0]['room_code']??null,
                'code'=>'AMBIGUOUS_OCCUPANCY',
                'occupancy_ids'=>array_map(static fn(array $row):int=>(int)$row['occupancy_id'],$rows),
            ];
        }
        return ['occupancies'=>$safe,'issues'=>$issues];
    }

    private static function multiplyMoney(int $unitsHundredths, int $rateCents): int
    {
        if ($unitsHundredths < 0 || $rateCents < 0) {
            throw new HttpException(422, 'Calculated amount must not be negative', 'AMOUNT_OVERFLOW');
        }
        // DECIMAL(14,2) can hold at most 999,999,999,999.99. Account
        // for half-up rounding before multiplying so neither PHP integers nor
        // the target MySQL column can overflow.
        $maximumProduct = self::MAX_DECIMAL_14_2_CENTS * 100 + 49;
        if ($unitsHundredths !== 0 && $rateCents > intdiv($maximumProduct, $unitsHundredths)) {
            throw new HttpException(422, 'Calculated amount is too large', 'AMOUNT_OVERFLOW');
        }
        return intdiv($unitsHundredths * $rateCents + 50, 100);
    }

    private static function sumMoney(int ...$amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0 || $amount > self::MAX_DECIMAL_14_2_CENTS - $total) {
                throw new HttpException(422, 'Calculated total is too large', 'AMOUNT_OVERFLOW');
            }
            $total += $amount;
        }
        return $total;
    }

    /** @param array<string,mixed> $preview */
    private function previewToken(array $preview,int $expiresAt): string
    {
        $payload=json_encode(['v'=>1,'exp'=>$expiresAt,'digest'=>$this->previewDigest($preview)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $body=rtrim(strtr(base64_encode($payload),'+/','-_'),'=');
        return $body.'.'.hash_hmac('sha256',$body,$this->app->config->appKey());
    }

    /** @param array<string,mixed> $preview */
    private function assertPreviewToken(string $token,array $preview): void
    {
        $parts=explode('.',$token,2);
        if(count($parts)!==2||$parts[0]===''||!preg_match('/^[A-Za-z0-9_-]+$/D',$parts[0])||!preg_match('/^[a-f0-9]{64}$/D',$parts[1])){
            throw new HttpException(422,'preview_token ไม่ถูกต้อง','BILL_PREVIEW_TOKEN_INVALID');
        }
        $expected=hash_hmac('sha256',$parts[0],$this->app->config->appKey());
        if(!hash_equals($expected,$parts[1]))throw new HttpException(422,'preview_token ไม่ถูกต้อง','BILL_PREVIEW_TOKEN_INVALID');
        $padding=(4-strlen($parts[0])%4)%4;
        $decoded=base64_decode(strtr($parts[0],'-_','+/').str_repeat('=',$padding),true);
        if(!is_string($decoded)||strlen($decoded)>1024)throw new HttpException(422,'preview_token ไม่ถูกต้อง','BILL_PREVIEW_TOKEN_INVALID');
        try{$payload=json_decode($decoded,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpException(422,'preview_token ไม่ถูกต้อง','BILL_PREVIEW_TOKEN_INVALID');}
        if(!is_array($payload)||($payload['v']??null)!==1||!is_int($payload['exp']??null)||!is_string($payload['digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$payload['digest'])){
            throw new HttpException(422,'preview_token ไม่ถูกต้อง','BILL_PREVIEW_TOKEN_INVALID');
        }
        if($payload['exp']<=time())throw new HttpException(409,'ตัวอย่างยอดหมดอายุ กรุณาตรวจยอดใหม่','BILL_PREVIEW_EXPIRED');
        if(!hash_equals($payload['digest'],$this->previewDigest($preview))){
            throw new HttpException(409,'ข้อมูลมิเตอร์ ผู้พัก หรืออัตราค่าใช้จ่ายเปลี่ยนหลังตรวจยอด กรุณาตรวจตัวอย่างใหม่ก่อนออกบิล','BILL_PREVIEW_CHANGED');
        }
    }

    /** @param array<string,mixed> $preview */
    private function previewDigest(array $preview): string
    {
        $canonical=$preview;
        unset($canonical['preview_token'],$canonical['preview_expires_at']);
        return hash('sha256',json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }

    private function newBillNumber(string $period): string
    {
        return 'B' . str_replace('-', '', $period) . '-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapSettings(array $row): array
    {
        return [
            'water_rate'=>(string)$row['water_rate'],'electric_rate'=>(string)$row['electric_rate'],'due_days'=>(int)$row['due_days'],
            'configured'=>isset($row['updated_by'])&&$row['updated_by']!==null,
            'updated_at'=>$row['updated_at'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapBill(array $row): array
    {
        foreach (['id','occupancy_id','resident_id','room_id'] as $key) if (isset($row[$key])) $row[$key] = (int)$row[$key];
        if (isset($row['period'])) $row['period'] = substr((string)$row['period'], 0, 7);
        if(isset($row['full_name']))$row['resident_name']=$row['full_name'];
        if(isset($row['line_linked']))$row['line_linked']=(bool)$row['line_linked'];
        $timezone=new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'));
        $row['display_status']=self::displayStatus((string)($row['status']??''),(string)($row['due_date']??''),new \DateTimeImmutable('now',$timezone));
        return $row;
    }

    private static function displayStatus(string $status,string $dueDate,?\DateTimeImmutable $now=null): string
    {
        if($status!=='pending'||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$dueDate))return $status;
        $today=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d');
        return $dueDate<$today?'overdue':'pending';
    }
}
