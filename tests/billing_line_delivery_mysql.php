<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^(?:appj_|dormitory_test)[A-Za-z0-9_]*$/D',(string)getenv('DB_DATABASE'))!==1){
    fwrite(STDERR,"Dedicated testing database required\n");exit(64);
}
$app=require dirname(__DIR__).'/bootstrap.php';
$pdo=$app->database()->pdo();
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$passed=0;
$check=static function(string $message,bool $condition)use($assert,&$passed):void{$assert($condition,$message);$passed++;fwrite(STDOUT,"PASS {$message}\n");};
final class BillingLineFixtureRollback extends RuntimeException {}
try{
    $app->database()->transaction(function(PDO $pdo)use($app,$check):void{
        $suffix=bin2hex(random_bytes(5));
        $insert=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,active,auth_version) VALUES(?,?,'owner',1,1)");
        $insert->execute(['bill_line_'.$suffix,Dormitory\Security\Password::hash('Testing-Only-2026-Strong!')]);$owner=(int)$pdo->lastInsertId();
        $identity='U'.bin2hex(random_bytes(16));
        $registry=new Dormitory\Domain\LineOfficialAccountService($app,static fn(string $token):array=>['userId'=>$identity,'basicId'=>'@billingtest']);
        (new ReflectionProperty($app,'lineOfficialAccounts'))->setValue($app,$registry);
        $oa=$registry->create(['slug'=>'bill-'.$suffix,'name'=>'Billing test','basic_id'=>'@billingtest','channel_access_token'=>'offline-billing-token-'.$suffix,'channel_secret'=>'offline-billing-secret-'.$suffix,'enabled'=>true],$owner);
        $room=$app->rooms()->create(['room_code'=>'BILL-LINE-'.$suffix,'floor'=>1,'room_type'=>'Test','monthly_rent'=>'4500.00']);
        $today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$period=substr($today,0,7);
        $resident=$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'Bill LINE Test','phone'=>'089'.substr(str_pad((string)random_int(0,9999999),7,'0',STR_PAD_LEFT),0,7),'move_in_date'=>$today,'opening_water_reading'=>'0.00','opening_electric_reading'=>'0.00','idempotency_key'=>'billing-line-'.$suffix]);
        $residentId=(int)$resident['resident_id'];
        $app->billing()->updateSettings(['water_rate'=>'0.00','electric_rate'=>'0.00','due_days'=>7],$owner);
        $app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'0.00','electric_current'=>'0.00'],$owner);
        $billing=['period'=>$period,'room_ids'=>[$room['id']],'due_date'=>$today,'confirm_current_period'=>true];
        $preview=$app->billing()->preview($billing);$created=$app->billing()->bulk($billing+['preview_token'=>$preview['preview_token']],$owner)['created'][0];
        $billId=(int)$created['id'];$expectedTotal=$created['total_amount'];
        $bind=static function()use($app,$residentId,$oa,$owner):void{$code=$app->lineRoomBindings()->issue($residentId,['oa_id'=>$oa['id'],'ttl_days'=>7,'replace_pending'=>false],$owner);$app->lineRoomBindings()->consume($code['code'],'U'.bin2hex(random_bytes(16)),$oa['id']);};
        $bind();$bind();$bind();
        $app->notifications()->enqueueBill($billId);
        $ids=$pdo->query('SELECT id FROM notification_outbox WHERE bill_id='.$billId.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $check('Each of three authorized LINE accounts gets one delivery',count($ids)===3);
        $pdo->exec("UPDATE notification_outbox SET status='sent',attempts=1,sent_at=UTC_TIMESTAMP(6),line_request_id='test-one' WHERE id=".(int)$ids[0]);
        $pdo->exec("UPDATE notification_outbox SET status='failed',attempts=2,last_error='Offline test failure' WHERE id=".(int)$ids[1]);
        $get=static function()use($app,$billId,$period):array{return array_values(array_filter($app->billing()->adminList($period),static fn(array $row):bool=>$row['id']===$billId));};
        $rows=$get();$check('One bill remains one list row with its original total',count($rows)===1&&$rows[0]['total_amount']===$expectedTotal);
        $row=$rows[0];$check('Mixed delivery counts preserve sent pending and failed recipients',$row['line_delivery_counts']===['total'=>3,'pending'=>1,'processing'=>0,'sent'=>1,'failed'=>1]);
        $check('Partial failure outranks sent and is retryable without exposing one recipient reference',$row['line_status']==='failed'&&$row['line_can_queue']===true&&$row['line_accepted_request_id']===null&&$row['line_request_id']===null);
        $pdo->exec("UPDATE notification_outbox SET status='sent',attempts=2,last_error=NULL,sent_at=UTC_TIMESTAMP(6) WHERE bill_id=".$billId);
        $row=$get()[0];$check('All sent recipients suppress redundant queueing',$row['line_status']==='sent'&&$row['line_can_queue']===false&&$row['line_delivery_counts']['sent']===3);
        $bind();$row=$get()[0];$check('A newly bound fourth account can receive an existing bill',$row['line_can_queue']===true&&$row['line_recipient_count']===4);
        $registry->update($oa['id'],['enabled'=>false],$owner);$row=$get()[0];
        $check('Disabled OA cannot leave bill queue controls enabled',$row['line_ready']===false&&$row['line_can_queue']===false);
        throw new BillingLineFixtureRollback('Rollback test fixtures');
    });
}catch(BillingLineFixtureRollback){}
fwrite(STDOUT,"{$passed} billing LINE MySQL checks passed; fixtures rolled back; no provider calls\n");
