<?php
declare(strict_types=1);

namespace Dormitory\Support;

use Dormitory\Http\HttpException;
use PDO;

/** Daily endpoints fail closed until their additive migration is complete. */
final class DailyBookingSchema
{
    public static function ready(PDO $pdo): bool
    {
        return self::errors($pdo) === [];
    }

    /** @return list<string> Metadata checks never expose stored guest or payment data. */
    public static function errors(PDO $pdo): array
    {
        $errors=[];
        $tables=['daily_bookings','daily_booking_nights','daily_room_blocks','daily_booking_actions','daily_housekeeping_actions',
            'payment_evidence_registry','payment_amount_registry','daily_transfer_instructions','daily_payments','daily_payment_actions','daily_refunds','daily_deposit_settlements'];
        $actual=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table)if(!in_array($table,$actual,true))$errors[]='Missing daily table: '.$table;
        $rows=$pdo->query("SELECT table_name,column_name,data_type,column_type,is_nullable,extra,generation_expression FROM information_schema.columns WHERE table_schema=DATABASE()
            AND table_name IN ('rooms','daily_bookings','daily_booking_nights','daily_payments','payments','payment_amount_registry','payment_evidence_registry')")->fetchAll();
        $columns=[];foreach($rows as $row){$row=array_change_key_case($row,CASE_LOWER);$columns[$row['table_name'].'.'.$row['column_name']]=$row;}
        foreach(['rental_mode'=>'enum','daily_rate'=>'decimal','max_guests'=>'smallint','daily_deposit'=>'decimal','housekeeping_status'=>'enum','housekeeping_version'=>'int'] as $name=>$type){$row=$columns['rooms.'.$name]??null;if(!$row||$row['data_type']!==$type)$errors[]='Missing or invalid daily room column: '.$name;}
        foreach(['expires_at'=>'datetime','access_token_hash'=>'char','request_hash'=>'char','version'=>'int'] as $name=>$type){$row=$columns['daily_bookings.'.$name]??null;if(!$row||$row['data_type']!==$type||$row['is_nullable']!=='NO')$errors[]='Missing or invalid daily booking column: '.$name;}
        foreach(['daily_payments.status'=>"enum('pending','verified','rejected','closed')",'payment_evidence_registry.claim_status'=>"enum('active','released')"]as$name=>$definition){$row=$columns[$name]??null;if(!$row||$row['column_type']!==$definition||$row['is_nullable']!=='NO')$errors[]='Missing or invalid daily review state: '.$name;}
        $generated=[
            'daily_booking_nights.active_room_id'=>'(case when (active = 1) then room_id else NULL end)',
            'daily_payments.active_booking_id'=>"(case when (status in ('pending','verified')) then booking_id else NULL end)",
            'payment_amount_registry.active_amount'=>"(case when (status in ('reserved','settled')) then transfer_amount else NULL end)",
            'payments.active_slip_hmac'=>"(case when (status in ('pending','verified')) then slip_hmac else NULL end)",
            'payments.credited_txn_ref'=>"(case when (status = 'verified') then transaction_ref else NULL end)",
            'daily_payments.active_slip_hmac'=>"(case when (status in ('pending','verified','closed')) then slip_hmac else NULL end)",
            'daily_payments.credited_txn_ref'=>"(case when (status = 'verified') then transaction_ref else NULL end)",
            'payment_evidence_registry.active_slip_hmac'=>"(case when (claim_status = 'active') then slip_hmac else NULL end)",
            'payment_evidence_registry.active_txn_ref'=>"(case when (claim_status = 'active') then transaction_ref else NULL end)",
        ];
        foreach($generated as $name=>$definition){$row=$columns[$name]??null;if(!$row||$row['extra']!=='STORED GENERATED'||$row['is_nullable']!=='YES'||self::normalizedCheck($row['generation_expression'])!==self::normalizedCheck($definition))$errors[]='Invalid daily generated uniqueness definition: '.$name;}
        $expected=[
            'daily_bookings.uq_daily_booking_idempotency'=>['idempotency_key'],
            'daily_booking_nights.uq_daily_active_room_night'=>['active_room_id','stay_date'],
            'daily_booking_nights.uq_daily_booking_night'=>['booking_id','stay_date'],
            'daily_booking_actions.uq_daily_action_key'=>['booking_id','idempotency_key'],
            'daily_housekeeping_actions.uq_daily_housekeeping_key'=>['room_id','idempotency_key'],
            'daily_room_blocks.uq_daily_block_key'=>['idempotency_key'],
            'payment_evidence_registry.uq_evidence_slip'=>['active_slip_hmac'],
            'payment_evidence_registry.uq_evidence_transaction'=>['active_txn_ref'],
            'payments.uq_payments_slip_hmac'=>['active_slip_hmac'],
            'payments.uq_payments_transaction_ref'=>['credited_txn_ref'],
            'payment_amount_registry.uq_amount_global_active'=>['active_amount'],
            'daily_payments.uq_daily_payment_active'=>['active_booking_id'],
            'daily_payments.uq_daily_payment_request'=>['request_key'],
            'daily_payments.uq_daily_payment_slip'=>['active_slip_hmac'],
            'daily_payments.uq_daily_payment_transaction'=>['credited_txn_ref'],
            'daily_payment_actions.uq_daily_payment_action_key'=>['payment_id','idempotency_key'],
            'daily_refunds.uq_daily_refund_request'=>['request_key'],
            'daily_deposit_settlements.uq_daily_deposit_request'=>['request_key'],
        ];
        $indexes=[];foreach($pdo->query("SELECT table_name,index_name,column_name,non_unique,sub_part FROM information_schema.statistics WHERE table_schema=DATABASE() ORDER BY table_name,index_name,seq_in_index")->fetchAll() as $row){$row=array_change_key_case($row,CASE_LOWER);$key=$row['table_name'].'.'.$row['index_name'];$indexes[$key]['columns'][]=$row['column_name'];$indexes[$key]['valid']=($indexes[$key]['valid']??true)&&(int)$row['non_unique']===0&&$row['sub_part']===null;}
        foreach($expected as $key=>$names)if(!isset($indexes[$key])||!$indexes[$key]['valid']||$indexes[$key]['columns']!==$names)$errors[]='Missing or invalid daily unique index: '.$key;
        $checks=['chk_rooms_daily_policy','chk_rooms_housekeeping_version','chk_daily_booking_dates','chk_daily_booking_money','chk_daily_booking_state','chk_daily_night_release','chk_daily_block_active','chk_daily_payment_amount','chk_daily_payment_balances','chk_daily_payment_lease','chk_daily_payment_verified','chk_daily_payment_evidence','chk_global_amount_settlement_time','chk_daily_transfer_amount','chk_daily_refund_positive','chk_daily_deposit_nonnegative','chk_global_transfer_positive'];
        $actual=[];foreach($pdo->query("SELECT t.constraint_name,t.enforced,c.check_clause FROM information_schema.table_constraints t JOIN information_schema.check_constraints c ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name WHERE t.constraint_schema=DATABASE() AND t.constraint_type='CHECK'")->fetchAll() as $row){$row=array_change_key_case($row,CASE_LOWER);$actual[$row['constraint_name']]=$row;}
        foreach($checks as $check)if(($actual[$check]['enforced']??'')!=='YES')$errors[]='Missing enforced daily check: '.$check;
        // Compare the full MySQL-rendered expression. Parentheses are security
        // relevant: removing them can turn an AND/OR policy or a multiplication
        // into a weaker predicate while retaining exactly the same tokens.
        foreach(self::renderedCheckDefinitions() as $name=>$definition){
            $row=$actual[$name]??null;
            if(!$row||$row['enforced']!=='YES'||self::normalizedCheck($row['check_clause'])!==self::normalizedCheck($definition))
                $errors[]='Missing or incompatible enforced daily definition: '.$name;
        }
        $triggers=['trg_daily_booking_insert_guard','trg_daily_booking_immutable','trg_daily_booking_no_delete','trg_daily_night_insert_guard','trg_daily_night_immutable','trg_daily_night_no_delete','trg_daily_action_no_update','trg_daily_action_no_delete','trg_monthly_booking_mode_guard','trg_monthly_occupancy_mode_guard','trg_daily_block_insert_guard','trg_daily_block_immutable','trg_daily_block_no_delete','trg_daily_housekeeping_no_update','trg_daily_housekeeping_no_delete','trg_daily_payment_insert','trg_daily_payment_update','trg_daily_payment_no_delete','trg_daily_transfer_no_update','trg_daily_transfer_no_delete','trg_daily_refund_no_update','trg_daily_refund_no_delete','trg_daily_deposit_no_update','trg_daily_deposit_no_delete',
            'trg_evidence_insert_guard','trg_evidence_immutable','trg_evidence_no_delete','trg_amount_immutable','trg_amount_no_delete','trg_daily_refund_insert','trg_daily_deposit_insert','trg_daily_transfer_insert'];
        array_push($triggers,'trg_daily_room_mode_guard','trg_daily_payment_action_insert','trg_daily_payment_action_no_update','trg_daily_payment_action_no_delete');
        $actual=$pdo->query("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
        // A SELECT/INSERT/UPDATE application account sees zero trigger rows. The last-installed
        // enforced completion checks above are its upgrade markers; privileged preflight can inspect names.
        if($actual!==[])foreach($triggers as $trigger)if(!in_array($trigger,$actual,true))$errors[]='Missing daily integrity trigger: '.$trigger;
        return $errors;
    }

    /** Exact canonical MySQL renderings; no live metadata determines expectations. */
    private static function renderedCheckDefinitions(): array
    {
        return json_decode(<<<'DAILY_CHECKS_JSON'
{
    "chk_daily_action_hash": "regexp_like(`request_hash`,_utf8mb4\\'^[0-9a-f]{64}$\\')",
    "chk_daily_action_name": "(`action` in (_utf8mb4\\'confirm\\',_utf8mb4\\'cancel\\',_utf8mb4\\'check-in\\',_utf8mb4\\'check-out\\',_utf8mb4\\'no-show\\'))",
    "chk_daily_block_active": "(((`active` = 1) and (`released_at` is null) and (`version` = 1)) or ((`active` = 0) and (`released_at` is not null) and (`version` = 2) and (`release_key` is not null) and (`release_hash` is not null)))",
    "chk_daily_block_dates": "(`end_date` > `start_date`)",
    "chk_daily_block_reason": "(char_length(trim(`reason`)) between 1 and 500)",
    "chk_daily_booking_dates": "((`check_out_date` > `check_in_date`) and ((to_days(`check_out_date`) - to_days(`check_in_date`)) <= 90))",
    "chk_daily_booking_guests": "(`guests` between 1 and 20)",
    "chk_daily_booking_hashes": "(regexp_like(`access_token_hash`,_ascii\\'^[0-9a-f]{64}$\\') and regexp_like(`request_hash`,_ascii\\'^[0-9a-f]{64}$\\'))",
    "chk_daily_booking_key": "regexp_like(`idempotency_key`,_ascii\\'^[A-Za-z0-9_-]{16,64}$\\')",
    "chk_daily_booking_money": "((`nightly_rate` > 0) and (`nightly_rate` <= 1000000) and (`deposit_amount` >= 0) and (`deposit_amount` <= 1000000) and (`room_amount` = (`nightly_rate` * (to_days(`check_out_date`) - to_days(`check_in_date`)))) and (`total_amount` = (`room_amount` + `deposit_amount`)))",
    "chk_daily_booking_name": "(char_length(trim(`full_name`)) between 2 and 150)",
    "chk_daily_booking_phone": "regexp_like(`phone_norm`,_utf8mb4\\'^0[0-9]{9}$\\')",
    "chk_daily_booking_schema_v18": "((`version` >= 1) and (char_length(`request_hash`) = 64) and (char_length(`access_token_hash`) = 64))",
    "chk_daily_booking_state": "(((`status` = _utf8mb4\\'pending\\') and (`confirmed_at` is null) and (`actual_check_in_at` is null) and (`actual_check_out_at` is null) and (`closed_at` is null)) or ((`status` = _utf8mb4\\'confirmed\\') and (`confirmed_at` is not null) and (`actual_check_in_at` is null) and (`actual_check_out_at` is null) and (`closed_at` is null)) or ((`status` = _utf8mb4\\'checked_in\\') and (`confirmed_at` is not null) and (`actual_check_in_at` is not null) and (`actual_check_out_at` is null) and (`closed_at` is null)) or ((`status` = _utf8mb4\\'checked_out\\') and (`confirmed_at` is not null) and (`actual_check_in_at` is not null) and (`actual_check_out_at` is not null) and (`closed_at` is not null)) or ((`status` in (_utf8mb4\\'cancelled\\',_utf8mb4\\'expired\\',_utf8mb4\\'no_show\\')) and (`actual_check_in_at` is null) and (`actual_check_out_at` is null) and (`closed_at` is not null)))",
    "chk_daily_booking_version": "(`version` >= 1)",
    "chk_daily_deposit_nonnegative": "(`retained_amount` >= 0)",
    "chk_daily_finance_schema_v19": "((`amount` > 0) and (`transfer_amount` >= `amount`))",
    "chk_daily_housekeeping_hash": "regexp_like(`request_hash`,_utf8mb4\\'^[0-9a-f]{64}$\\')",
    "chk_daily_night_rate": "((`nightly_rate` > 0) and (`nightly_rate` <= 1000000))",
    "chk_daily_night_release": "(((`active` = 1) and (`released_at` is null)) or ((`active` = 0) and (`released_at` is not null)))",
    "chk_daily_payment_action_hash": "regexp_like(`request_hash`,_utf8mb4\\'^[0-9a-f]{64}$\\')",
    "chk_daily_payment_action_reason": "(char_length(trim(`reason`)) between 3 and 450)",
    "chk_daily_payment_amount": "((`amount` > 0) and (`transfer_amount` >= `amount`))",
    "chk_daily_payment_balances": "((`refunded_amount` >= 0) and (`deposit_refunded_amount` >= 0) and (`deposit_retained_amount` >= 0) and (`deposit_refunded_amount` <= `refunded_amount`) and ((`refunded_amount` + `deposit_retained_amount`) <= `transfer_amount`))",
    "chk_daily_payment_evidence": "(((`method` = _utf8mb4\\'slip\\') and (`slip_path` is not null) and (`slip_mime` is not null) and (`slip_hmac` is not null) and (`recorded_by` is null)) or ((`method` = _utf8mb4\\'cash\\') and (`status` = _utf8mb4\\'verified\\') and (`recorded_by` is not null) and (`receipt_reference` is not null) and (`request_key` is not null) and (`slip_path` is null) and (`slip_hmac` is null) and (`transaction_ref` is null)))",
    "chk_daily_payment_lease": "((`verification_token` is null) = (`verification_lease_until` is null))",
    "chk_daily_payment_review_v20": "((`status` <> _utf8mb4\\'closed\\') or ((`verification_token` is null) and (`verified_at` is null)))",
    "chk_daily_payment_verified": "((`status` = _utf8mb4\\'verified\\') = (`verified_at` is not null))",
    "chk_daily_refund_positive": "(`amount` > 0)",
    "chk_daily_transfer_amount": "((`booking_amount` > 0) and (`adjustment_amount` between 0.01 and 0.99) and (`transfer_amount` = (`booking_amount` + `adjustment_amount`)))",
    "chk_global_amount_settlement_time": "((`status` <> _utf8mb4\\'settled\\') or (`settled_at` is not null))",
    "chk_global_transfer_positive": "(`transfer_amount` > 0)",
    "chk_rooms_amenities_array": "(json_type(`amenities`) = _utf8mb4\\'ARRAY\\')",
    "chk_rooms_code": "(char_length(trim(`room_code`)) between 1 and 32)",
    "chk_rooms_daily_policy": "((`max_guests` between 1 and 20) and (`daily_deposit` between 0 and 1000000) and ((`daily_rate` is null) or ((`daily_rate` > 0) and (`daily_rate` <= 1000000))) and ((`rental_mode` = _utf8mb4\\'monthly\\') or (`daily_rate` is not null)))",
    "chk_rooms_floor": "(`floor` between 1 and 999)",
    "chk_rooms_housekeeping_version": "(`housekeeping_version` >= 1)",
    "chk_rooms_image_key": "((`image_key` is null) or (`image_key` in (_utf8mb4\\'room-standard.jpg\\',_utf8mb4\\'room-deluxe.jpg\\',_utf8mb4\\'room-suite.jpg\\',_utf8mb4\\'room-studio.jpg\\')))",
    "chk_rooms_monthly_rent": "((`monthly_rent` >= 0) and (`monthly_rent` <= 1000000) and ((`rental_mode` = _utf8mb4\\'daily\\') or (`monthly_rent` > 0)))",
    "chk_rooms_type": "(char_length(trim(`room_type`)) between 1 and 50)"
}
DAILY_CHECKS_JSON, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Preserve string literals and every parenthesis in integrity expressions. */
    private static function normalizedCheck(string $expression): string
    {
        $expression=str_replace("\\'","'",$expression);
        $result='';$inLiteral=false;$length=strlen($expression);
        for($index=0;$index<$length;$index++){
            $character=$expression[$index];
            if(!$inLiteral&&$character==='_'){
                if(preg_match("/^_[a-z0-9]+(?=')/i",substr($expression,$index),$prefix)===1){$index+=strlen($prefix[0])-1;continue;}
            }
            if($character==="'"){
                $result.=$character;
                if($inLiteral&&$index+1<$length&&$expression[$index+1]==="'"){$result.="'";$index++;continue;}
                $inLiteral=!$inLiteral;continue;
            }
            if($inLiteral)$result.=$character;
            elseif($character!=='`'&&!ctype_space($character))$result.=strtolower($character);
        }
        return $result;
    }

    public static function assertReady(PDO $pdo): void
    {
        if (!self::ready($pdo)) {
            throw new HttpException(503, 'ระบบจองรายวันยังไม่พร้อม กรุณาให้เจ้าของติดตั้งฐานข้อมูลรายวัน', 'DAILY_SCHEMA_NOT_READY');
        }
    }
}
