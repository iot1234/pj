<?php
declare(strict_types=1);

// Deliberately changes CHECKs only in an explicitly opted-in disposable schema.
// No bootstrap or .env loading; DBA credentials must be injected by the fixture runner.
$database=(string)getenv('DB_DATABASE');
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('DAILY_SCHEMA_FAULT_TEST')!=='1'
    ||preg_match('/^appj_daily_schema_[a-z0-9_]+$/D',$database)!==1){fwrite(STDERR,"Refusing daily schema fault test outside an opted-in disposable schema\n");exit(64);}
$pdo=new PDO('mysql:host='.(getenv('DB_HOST')?:'127.0.0.1').';port='.(getenv('DB_PORT')?:'3306').';dbname='.$database.';charset=utf8mb4',
    (string)getenv('DB_USERNAME'),(string)getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
require_once dirname(__DIR__).'/src/Support/DailyBookingSchema.php';
use Dormitory\Support\DailyBookingSchema;
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$errors=DailyBookingSchema::errors($pdo);$assert($errors===[],'Canonical isolated schema rejected: '.implode('; ',$errors));
$assert((int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM daily_bookings')->fetchColumn()===0,'Grouping fault tests require empty disposable room/booking tables');
$checks=[
    'daily_bookings'=>['chk_daily_booking_money','chk_daily_booking_state','chk_daily_booking_dates','chk_daily_booking_schema_v18'],
    'daily_booking_nights'=>['chk_daily_night_release'],
    'daily_room_blocks'=>['chk_daily_block_active'],
    'daily_payments'=>['chk_daily_payment_balances','chk_daily_payment_evidence','chk_daily_finance_schema_v19','chk_daily_payment_review_v20'],
    'daily_payment_actions'=>['chk_daily_payment_action_hash','chk_daily_payment_action_reason'],
    'payment_amount_registry'=>['chk_global_amount_settlement_time'],
];
$groups=0;
foreach($checks as$table=>$names)foreach($names as$name){
    $query=$pdo->prepare('SELECT check_clause FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name=?');$query->execute([$name]);$clause=$query->fetchColumn();$assert(is_string($clause)&&$clause!=='','Missing original check '.$name);$clause=str_replace("\\'","'",$clause);
    try{
        $pdo->exec('ALTER TABLE `'.$table.'` ALTER CHECK `'.$name.'` NOT ENFORCED');
        $errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,$name)))>0,'Unenforced check falsely passed: '.$name);
        $pdo->exec('ALTER TABLE `'.$table.'` ALTER CHECK `'.$name.'` ENFORCED');
        $pdo->exec('ALTER TABLE `'.$table.'` DROP CHECK `'.$name.'`');
        $pdo->exec('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$name.'` CHECK (TRUE)');
        $errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,$name)))>0,'CHECK TRUE with the same name falsely passed: '.$name);
    }finally{
        $pdo->exec('ALTER TABLE `'.$table.'` DROP CHECK `'.$name.'`');
        $pdo->exec('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$name.'` CHECK ('.$clause.')');
    }
    $assert(DailyBookingSchema::errors($pdo)===[],'Restored canonical check failed: '.$name);$groups++;fwrite(STDOUT,'PASS detects unenforced and weakened '.$name."\n");
}
$regrouped=[
    'daily_bookings'=>['chk_daily_booking_money'=>
        'nightly_rate>0 AND nightly_rate<=1000000 AND deposit_amount>=0 AND deposit_amount<=1000000 AND room_amount=nightly_rate*TO_DAYS(check_out_date)-TO_DAYS(check_in_date) AND total_amount=room_amount+deposit_amount'],
    'rooms'=>['chk_rooms_daily_policy'=>
        "max_guests BETWEEN 1 AND 20 AND daily_deposit BETWEEN 0 AND 1000000 AND daily_rate IS NULL OR daily_rate>0 AND daily_rate<=1000000 AND rental_mode='monthly' OR daily_rate IS NOT NULL"],
];
foreach($regrouped as$table=>$definitions)foreach($definitions as$name=>$weakened){
    $q=$pdo->prepare('SELECT check_clause FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name=?');$q->execute([$name]);$original=str_replace("\\'","'",(string)$q->fetchColumn());
    try{
        $pdo->exec('ALTER TABLE `'.$table.'` DROP CHECK `'.$name.'`');
        // NOT ENFORCED allows existing valid bookings while this intentionally
        // incorrect arithmetic is installed solely for a readiness comparison.
        $pdo->exec('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$name.'` CHECK ('.$weakened.') NOT ENFORCED');
        $errors=DailyBookingSchema::errors($pdo);
        $assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,'incompatible enforced daily definition: '.$name)))>0,'Regrouped same-token predicate falsely passed: '.$name);
        // The empty-schema runner can enforce these variants, proving the
        // rejection also comes from the clause body, independent of ENFORCED.
        if((int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===0){
            $pdo->exec('ALTER TABLE `'.$table.'` ALTER CHECK `'.$name.'` ENFORCED');
            $errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,$name)))>0,'Enforced regrouped same-token predicate falsely passed: '.$name);
        }
    }finally{
        $pdo->exec('ALTER TABLE `'.$table.'` DROP CHECK `'.$name.'`');$pdo->exec('ALTER TABLE `'.$table.'` ADD CONSTRAINT `'.$name.'` CHECK ('.$original.')');
    }
    $assert(DailyBookingSchema::errors($pdo)===[],'Restored grouped canonical check failed: '.$name);$groups++;fwrite(STDOUT,'PASS rejects same-token grouping change in '.$name."\n");
}
$query=$pdo->query("SELECT generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_amount_registry' AND column_name='active_amount'");
$generated=str_replace("\\'","'",(string)$query->fetchColumn());$assert($generated!=='','Missing canonical global amount generation');
$assert((int)$pdo->query('SELECT COUNT(*) FROM payment_amount_registry')->fetchColumn()===0,'Generated expression fault requires an empty disposable registry');
$installed=false;
try{
    try{
        $pdo->exec("ALTER TABLE payment_amount_registry MODIFY COLUMN active_amount DECIMAL(14,2) GENERATED ALWAYS AS (CASE WHEN (subject_type='daily' OR status) IN ('reserved','settled') THEN transfer_amount ELSE NULL END) STORED");$installed=true;
    }catch(PDOException $error){
        // MySQL may reject the boolean/string coercion during generated-column
        // compilation, before the readiness checker can observe the bad DDL.
        $assert((int)($error->errorInfo[1]??0)===1292,'Unexpected generated-column DDL rejection');
        $q=$pdo->query("SELECT generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payment_amount_registry' AND column_name='active_amount'");
        $assert(str_replace("\\'","'",(string)$q->fetchColumn())===$generated&&DailyBookingSchema::errors($pdo)===[],'Rejected generated-column DDL changed canonical schema');
    }
    if($installed){$errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,'payment_amount_registry.active_amount')))>0,'Same-token regrouped generated amount guard falsely passed');}
}finally{
    if($installed)$pdo->exec('ALTER TABLE payment_amount_registry MODIFY COLUMN active_amount DECIMAL(14,2) GENERATED ALWAYS AS ('.$generated.') STORED');
}
$assert(DailyBookingSchema::errors($pdo)===[],'Restored generated global amount guard failed');$groups++;fwrite(STDOUT,"PASS database or readiness rejects ambiguous generated CASE; canonical expression remains intact\n");
$proofProjections=[
    'payments'=>['active_slip_hmac'=>"CHAR(64) CHARACTER SET ascii COLLATE ascii_bin",'credited_txn_ref'=>'VARCHAR(191)'],
    'daily_payments'=>['active_slip_hmac'=>"CHAR(64) CHARACTER SET ascii COLLATE ascii_bin",'credited_txn_ref'=>'VARCHAR(191)'],
    'payment_evidence_registry'=>['active_slip_hmac'=>"CHAR(64) CHARACTER SET ascii COLLATE ascii_bin",'active_txn_ref'=>'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],
];
foreach($proofProjections as$table=>$definitions)foreach($definitions as$column=>$type){
    $q=$pdo->prepare('SELECT generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);$original=str_replace("\\'","'",(string)$q->fetchColumn());
    try{
        $pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $type GENERATED ALWAYS AS (NULL) STORED");
        $errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,$table.'.'.$column)))>0,'Disabled proof projection falsely passed: '.$table.'.'.$column);
    }finally{$pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $type GENERATED ALWAYS AS ($original) STORED");}
    $assert(DailyBookingSchema::errors($pdo)===[],'Restored proof projection rejected');$groups++;fwrite(STDOUT,"PASS disabled proof projection fails readiness: $table.$column\n");
}
try{
    $pdo->exec('ALTER TABLE daily_payment_actions DROP INDEX uq_daily_payment_action_key, ADD KEY uq_daily_payment_action_key(payment_id,idempotency_key)');
    $errors=DailyBookingSchema::errors($pdo);$assert(count(array_filter($errors,static fn(string$error):bool=>str_contains($error,'uq_daily_payment_action_key')))>0,'Nonunique close action key falsely passed');
}finally{$pdo->exec('ALTER TABLE daily_payment_actions DROP INDEX uq_daily_payment_action_key, ADD UNIQUE KEY uq_daily_payment_action_key(payment_id,idempotency_key)');}
$assert(DailyBookingSchema::errors($pdo)===[],'Restored close action uniqueness rejected');$groups++;fwrite(STDOUT,"PASS close action must retain full unique request key\n");
fwrite(STDOUT,"{$groups} daily schema fault groups passed; original CHECKs restored\n");
