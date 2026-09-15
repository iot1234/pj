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
    private const MAX_DUE_DAYS = 60;

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

    /**
     * Build an automation-safe plan for a completed calendar month. The
     * current month is deliberately rejected so a scheduler cannot freeze
     * readings while that month is still in progress.
     *
     * @return array<string,mixed>
     */
    public function closedPeriodPreview(string $period, ?string $dueDate = null): array
    {
        $period = Validator::period($period);
        $this->assertClosedBillingPeriod($period);
        $settings = $this->settings();
        if (($settings['configured'] ?? false) !== true) {
            throw new HttpException(
                409,
                'Billing settings must be confirmed before monthly generation',
                'BILLING_SETTINGS_NOT_CONFIRMED'
            );
        }

        $timezone = new \DateTimeZone((string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $today = new \DateTimeImmutable('today', $timezone);
        $dueDate ??= $today->modify('+' . (int) $settings['due_days'] . ' days')->format('Y-m-d');
        $periodDate = $period . '-01';
        $lastDay = (new \DateTimeImmutable($periodDate))->modify('last day of this month')->format('Y-m-d');
        $statement = $this->app->database()->pdo()->prepare(
            'SELECT DISTINCT o.room_id
               FROM occupancies o
              WHERE o.move_in_date<=?
                AND (o.move_out_date IS NULL OR o.move_out_date>=?)
                AND NOT EXISTS (
                    SELECT 1
                      FROM bills b
                     WHERE b.occupancy_id=o.id
                       AND b.period=?
                )
              ORDER BY o.room_id
              LIMIT 501'
        );
        $statement->execute([$lastDay, $periodDate, $periodDate]);
        $roomIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count($roomIds) > 500) {
            throw new HttpException(
                409,
                'Completed-month generation exceeds the safe 500-room batch limit',
                'BILL_AUTOMATION_BATCH_TOO_LARGE',
                ['maximum_rooms' => 500]
            );
        }

        if ($roomIds === []) {
            // Still validate the supplied/default due date so a dry run cannot
            // report a malformed plan as safe merely because no room matches.
            $this->validatedBillingDates($period, $dueDate);
            return [
                'period' => $period,
                'due_date' => $dueDate,
                'room_ids' => [],
                'bills' => [],
                'issues' => [],
                'preview_token' => null,
                'preview_expires_at' => null,
                'automation_safe' => true,
            ];
        }

        $preview = $this->preview([
            'period' => $period,
            'room_ids' => $roomIds,
            'due_date' => $dueDate,
        ]);
        $preview['room_ids'] = $roomIds;
        $preview['automation_safe'] = true;
        return $preview;
    }

    /**
     * Generate a completed month atomically and idempotently. Occupancies that
     * already have a bill are excluded from the plan, so a complete rerun is a
     * safe no-op; any missing/ambiguous meter state aborts the whole run
     * instead of silently producing a partial month.
     *
     * @param null|callable(array<string,mixed>):void $afterCreate
     * @return array<string,mixed>
     */
    public function generateClosedPeriod(
        string $period,
        int $adminId,
        ?string $dueDate = null,
        ?callable $afterCreate = null,
    ): array {
        return $this->app->database()->transaction(function (PDO $pdo) use (
            $period,
            $adminId,
            $dueDate,
            $afterCreate,
        ): array {
            $this->assertActiveAdmin($pdo, $adminId);
            $plan = $this->closedPeriodPreview($period, $dueDate);
            if ($plan['issues'] !== []) {
                throw new HttpException(
                    409,
                    'Completed-month generation is blocked by unresolved room data',
                    'BILL_AUTOMATION_BLOCKED',
                    ['issues' => $plan['issues']]
                );
            }
            if ($plan['room_ids'] === []) {
                $result = [
                    'period' => $plan['period'],
                    'created' => [],
                    'skipped' => [],
                    'automation_safe' => true,
                ];
                if ($afterCreate !== null) {
                    $afterCreate($result);
                }
                return $result;
            }

            $result = $this->bulk([
                'period' => $plan['period'],
                'room_ids' => $plan['room_ids'],
                'due_date' => $plan['due_date'],
                'preview_token' => $plan['preview_token'],
            ], $adminId);
            $result['automation_safe'] = true;
            if ($afterCreate !== null) {
                $afterCreate($result);
            }
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function updateSettings(array $input, int $adminId): array
    {
        Validator::only($input, ['water_rate', 'electric_rate', 'due_days']);
        $water = Validator::scaledDecimal($input['water_rate'] ?? null, 'water_rate', 2, 7);
        $electric = Validator::scaledDecimal($input['electric_rate'] ?? null, 'electric_rate', 2, 7);
        if($water>100_000_000||$electric>100_000_000)throw new HttpException(422,'Billing rates must not exceed 1,000,000.00','VALIDATION_ERROR');
        try {
            $dueDays = Validator::id($input['due_days'] ?? null, 'due_days');
        } catch (HttpException) {
            throw new HttpException(422, 'due_days must be between 1 and 60', 'VALIDATION_ERROR', ['field' => 'due_days']);
        }
        if ($dueDays > self::MAX_DUE_DAYS) {
            throw new HttpException(422, 'due_days must be between 1 and 60', 'VALIDATION_ERROR', ['field' => 'due_days']);
        }
        $pdo = $this->app->database()->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO billing_settings (id,water_rate,electric_rate,due_days,updated_by,updated_at)
             VALUES (1,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE water_rate=VALUES(water_rate),electric_rate=VALUES(electric_rate),due_days=VALUES(due_days),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
        );
        $statement->execute([
            Validator::decimalString($water), Validator::decimalString($electric), $dueDays, $adminId,
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
        Validator::only($input,[
            'period','room_ids','water_rate','electric_rate','other_description',
            'other_amount','due_date','preview_token','confirm_current_period',
        ]);
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
            if ($created !== []) {
                $ids = array_column($created,'id'); sort($ids,SORT_NUMERIC);
                LineAdminEvents::enqueue($this->app,'billing.created',hash('sha256',implode(',',$ids)));
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
                    (SELECT p.status
                       FROM payments p
                      WHERE p.bill_id=b.id
                      ORDER BY p.id DESC
                      LIMIT 1) AS payment_status,
                    res.id AS line_resident_id
               FROM bills b
               JOIN rooms r ON r.id=b.room_id
               JOIN residents res ON res.id=b.resident_id' . $where .
            ' ORDER BY b.period DESC,r.floor,r.room_code'
        );
        $statement->execute($parameters);
        $rows=$statement->fetchAll();
        if($rows===[])return [];
        // Fetch deliveries separately: a direct one-to-many join repeats the
        // bill and inflates both the amount due and the billing summary.
        $deliveries=[];
        foreach(array_chunk(array_column($rows,'id'),200) as $billIds){
            $deliveryQuery=$this->app->database()->pdo()->prepare(
                "SELECT bill_id,line_binding_id,line_oa_id,status,attempts,last_error,sent_at,line_request_id,line_accepted_request_id
                 FROM notification_outbox WHERE purpose='bill_delivery' AND bill_id IN (".implode(',',array_fill(0,count($billIds),'?')).") ORDER BY id DESC");
            $deliveryQuery->execute($billIds);
            foreach($deliveryQuery->fetchAll() as $delivery)$deliveries[(int)$delivery['bill_id']][]=$delivery;
        }
        $oaReady=[];
        foreach($this->app->lineOfficialAccounts()->all() as $oa)$oaReady[(int)$oa['id']]=($oa['line_binding_ready']??false)===true;
        $recipients=[];
        foreach($rows as &$row){
            $residentId=(int)$row['line_resident_id'];
            $recipients[$residentId]??=$this->app->notifications()->recipients($residentId);
            $targets=$recipients[$residentId];
            $readyTargets=array_values(array_filter($targets,static fn(array $target):bool=>$oaReady[(int)$target['oa_id']]??false));
            $billDeliveries=$deliveries[(int)$row['id']]??[];
            $row+=self::summarizeLineDeliveries($billDeliveries);
            $row['line_linked']=$targets!==[];
            $row['line_ready']=$readyTargets!==[];
            $row['line_recipient_count']=count($readyTargets);
            $deliveryStates=[];
            foreach($billDeliveries as $delivery)$deliveryStates[(int)($delivery['line_binding_id']??0)]=$delivery['status'];
            $needsDelivery=false;
            foreach($readyTargets as $target){
                $deliveryState=$deliveryStates[(int)$target['id']]??null;
                if($deliveryState===null||$deliveryState==='failed'){$needsDelivery=true;break;}
            }
            $row['line_can_queue']=$row['status']==='pending'
                && !in_array($row['payment_status'],['pending','verified'],true) && $needsDelivery;
            unset($row['line_resident_id']);
        }
        unset($row);
        return array_map($this->mapBill(...), $rows);
    }

    /** A sent recipient must never hide another recipient's failure or pending work. */
    private static function summarizeLineDeliveries(array $deliveries): array
    {
        $counts=['total'=>count($deliveries),'pending'=>0,'processing'=>0,'sent'=>0,'failed'=>0];
        foreach($deliveries as $delivery){$status=(string)($delivery['status']??'');if(array_key_exists($status,$counts)&&$status!=='total')$counts[$status]++;}
        $status=null;
        foreach(['failed','processing','pending','sent'] as $candidate){if($counts[$candidate]>0){$status=$candidate;break;}}
        $representative=null;
        foreach($deliveries as $delivery){if(($delivery['status']??null)===$status){$representative=$delivery;break;}}
        $single=count($deliveries)===1?$deliveries[0]:null;
        return ['line_status'=>$status,'line_delivery_counts'=>$counts,
            'line_attempts'=>$deliveries===[]?null:max(array_map(static fn(array $row):int=>(int)($row['attempts']??0),$deliveries)),
            'line_last_error'=>$status==='failed'?($representative['last_error']??null):null,
            'line_sent_at'=>$status==='sent'?max(array_map(static fn(array $row):string=>(string)($row['sent_at']??''),$deliveries)):null,
            'line_request_id'=>$single['line_request_id']??null,'line_accepted_request_id'=>$single['line_accepted_request_id']??null];
    }

    /** @return list<array<string,mixed>> */
    public function residentList(int $residentId): array
    {
        $statement = $this->app->database()->pdo()->prepare(
            'SELECT b.*,b.room_code_snapshot AS room_code,
                    (SELECT p.status
                       FROM payments p
                      WHERE p.bill_id=b.id
                      ORDER BY p.id DESC
                      LIMIT 1) AS payment_status
               FROM bills b
              WHERE b.resident_id=?
              ORDER BY b.period DESC,b.id DESC'
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
        Validator::only($input,[
            'period','room_ids','water_rate','electric_rate','other_description',
            'other_amount','due_date','confirm_current_period',
        ]);
        [$period, $periodDate, $dueDate] = $this->validatedBillingDates(
            $input['period'] ?? null,
            $input['due_date'] ?? null,
        );
        $timezone=new \DateTimeZone((string)$this->app->config->get('APP_TIMEZONE','Asia/Bangkok'));
        $currentPeriod=(new \DateTimeImmutable('now',$timezone))->format('Y-m');
        $currentPeriodConfirmed=array_key_exists('confirm_current_period',$input)
            ?Validator::boolean($input['confirm_current_period'],'confirm_current_period')
            :false;
        if($period===$currentPeriod&&!$currentPeriodConfirmed){
            throw new HttpException(
                409,
                'The current billing period must be explicitly finalized before issuing bills',
                'CURRENT_BILLING_PERIOD_NOT_FINALIZED',
                ['period'=>$period,'confirmation_required'=>true]
            );
        }
        if (array_key_exists('other_description', $input)
            && $input['other_description'] !== null
            && !is_string($input['other_description'])) {
            throw new HttpException(422, 'other_description is invalid', 'VALIDATION_ERROR', ['field' => 'other_description']);
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
        } elseif (array_key_exists('other_description', $input) && $input['other_description'] !== null) {
            if (trim($input['other_description']) !== '') {
                throw new HttpException(422, 'other_description requires a positive other_amount', 'VALIDATION_ERROR', ['field' => 'other_amount']);
            }
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
        $sql = 'SELECT o.id AS occupancy_id,o.resident_id,o.room_id,o.monthly_rent,
                       o.move_in_date,o.opening_water_reading,o.opening_electric_reading,
                       r.room_code,res.full_name
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
            if($occupancy['opening_water_reading']===null||$occupancy['opening_electric_reading']===null){
                $issues[]=[
                    'room_id'=>(int)$occupancy['room_id'],
                    'room_code'=>$occupancy['room_code'],
                    'occupancy_id'=>(int)$occupancy['occupancy_id'],
                    'code'=>'METER_OPENING_REQUIRED',
                ];
                continue;
            }
            $meter = $pdo->prepare('SELECT meter_type,occupancy_id,previous_reading,current_reading,units_used
                FROM meter_readings WHERE room_id=? AND period=?' . ($lock ? ' FOR UPDATE' : ''));
            $meter->execute([$occupancy['room_id'], $periodDate]);
            $readings = [];
            $mismatched=[];
            foreach ($meter->fetchAll() as $row) {
                if((int)($row['occupancy_id']??0)!==(int)$occupancy['occupancy_id']){
                    $mismatched[]=(string)$row['meter_type'];
                    continue;
                }
                $readings[$row['meter_type']] = $row;
            }
            if($mismatched!==[]){
                $issues[]=[
                    'room_id'=>(int)$occupancy['room_id'],
                    'room_code'=>$occupancy['room_code'],
                    'occupancy_id'=>(int)$occupancy['occupancy_id'],
                    'code'=>'METER_OCCUPANCY_MISMATCH',
                    'meter_types'=>$mismatched,
                ];
                continue;
            }
            $missing = array_values(array_diff(['water','electric'], array_keys($readings)));
            if ($missing !== []) {
                $issues[] = ['room_id'=>(int)$occupancy['room_id'],'room_code'=>$occupancy['room_code'],'code'=>'MISSING_METER','meter_types'=>$missing];
                continue;
            }
            $baselineIssues=[];
            $moveInPeriod=substr((string)$occupancy['move_in_date'],0,7).'-01';
            $requiredPriorPeriod=(new \DateTimeImmutable($periodDate,new \DateTimeZone('UTC')))
                ->modify('first day of previous month')
                ->format('Y-m-01');
            foreach(['water','electric'] as$type){
                $expectedPrevious=null;
                if($moveInPeriod===$periodDate){
                    $openingField='opening_'.$type.'_reading';
                    if($occupancy[$openingField]===null){
                        $baselineIssues[]=[
                            'meter_type'=>$type,
                            'code'=>'METER_OPENING_REQUIRED',
                        ];
                        continue;
                    }
                    $expectedPrevious=Validator::scaledDecimal(
                        $occupancy[$openingField],
                        $openingField,
                        2,
                        12
                    );
                }else{
                    $prior=$pdo->prepare('SELECT current_reading
                        FROM meter_readings
                        WHERE occupancy_id=? AND room_id=? AND meter_type=? AND period=?
                        LIMIT 1'.($lock?' FOR UPDATE':''));
                    $prior->execute([
                        $occupancy['occupancy_id'],
                        $occupancy['room_id'],
                        $type,
                        $requiredPriorPeriod,
                    ]);
                    $priorCurrent=$prior->fetchColumn();
                    if($priorCurrent===false){
                        $baselineIssues[]=[
                            'meter_type'=>$type,
                            'code'=>'METER_HISTORY_GAP',
                            'required_previous_period'=>substr($requiredPriorPeriod,0,7),
                        ];
                        continue;
                    }
                    $expectedPrevious=Validator::scaledDecimal(
                        $priorCurrent,
                        'previous_reading',
                        2,
                        12
                    );
                }
                $storedPrevious=Validator::scaledDecimal(
                    $readings[$type]['previous_reading'],
                    'previous_reading',
                    2,
                    12
                );
                if($storedPrevious!==$expectedPrevious){
                    $baselineIssues[]=[
                        'meter_type'=>$type,
                        'code'=>'METER_BASELINE_MISMATCH',
                    ];
                }
            }
            if($baselineIssues!==[]){
                $issues[]=[
                    'room_id'=>(int)$occupancy['room_id'],
                    'room_code'=>$occupancy['room_code'],
                    'occupancy_id'=>(int)$occupancy['occupancy_id'],
                    'code'=>'METER_CHAIN_INVALID',
                    'meter_issues'=>$baselineIssues,
                ];
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
        return [
            'period'=>$period,
            'due_date'=>$dueDate,
            'current_period_confirmed'=>$period===$currentPeriod&&$currentPeriodConfirmed,
            'bills'=>$bills,
            'issues'=>$issues,
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function validatedBillingDates(
        mixed $rawPeriod,
        mixed $rawDueDate,
        ?\DateTimeImmutable $now = null,
    ): array
    {
        $period = Validator::period($rawPeriod);
        $periodDate = $period . '-01';
        $timezone = new \DateTimeZone((string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $today = $now === null
            ? new \DateTimeImmutable('today', $timezone)
            : $now->setTimezone($timezone)->setTime(0, 0);
        $maximumPeriod = $today->format('Y-m');
        if ($period > $maximumPeriod) {
            throw new HttpException(422, 'period cannot be later than the current month', 'VALIDATION_ERROR', [
                'field' => 'period', 'maximum' => $maximumPeriod,
            ]);
        }
        $dueDate = Validator::date($rawDueDate, 'due_date');
        if ($dueDate < $periodDate) {
            throw new HttpException(422, 'due_date cannot precede the billing period', 'VALIDATION_ERROR', ['field' => 'due_date']);
        }
        $maximumDueDate = $today->modify('+' . self::MAX_DUE_DAYS . ' days')->format('Y-m-d');
        if ($dueDate > $maximumDueDate) {
            throw new HttpException(422, 'due_date cannot be more than ' . self::MAX_DUE_DAYS . ' days from today', 'VALIDATION_ERROR', [
                'field' => 'due_date', 'maximum' => $maximumDueDate,
            ]);
        }
        return [$period, $periodDate, $dueDate];
    }

    private function assertClosedBillingPeriod(
        string $period,
        ?\DateTimeImmutable $now = null,
    ): void {
        $timezone = new \DateTimeZone((string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $currentPeriod = ($now ?? new \DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone)
            ->format('Y-m');
        if ($period >= $currentPeriod) {
            throw new HttpException(
                409,
                'Automated billing only accepts a completed calendar month',
                'BILL_PERIOD_NOT_CLOSED',
                ['period' => $period, 'maximum' => (new \DateTimeImmutable($currentPeriod . '-01'))->modify('-1 month')->format('Y-m')]
            );
        }
    }

    private function assertActiveAdmin(PDO $pdo, int $adminId): void
    {
        if ($adminId < 1) {
            throw new HttpException(422, 'admin_id is invalid', 'VALIDATION_ERROR', ['field' => 'admin_id']);
        }
        $statement = $pdo->prepare('SELECT id FROM admin_users WHERE id=? AND active=1 FOR SHARE');
        $statement->execute([$adminId]);
        if ($statement->fetchColumn() === false) {
            throw new HttpException(403, 'Active administrator is required', 'ADMIN_INACTIVE');
        }
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
