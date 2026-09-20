<?php
declare(strict_types=1);

use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Domain\NotificationService;
use Dormitory\Domain\LineDeliveryException;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_line_test_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){fwrite(STDERR,"Dedicated empty LINE testing database required\n");exit(64);}
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','residents','bills','line_room_bindings','line_admin_recipients','line_notice_outbox']as$table)if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0)throw new RuntimeException('Fresh fixture required');
$assert=static function(bool $ok,string $why='Assertion failed'):void{if(!$ok)throw new RuntimeException($why);};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$passed=0;$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;echo "PASS {$name}\n";};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active)VALUES('platform_test_owner',?,'owner',1,1)");$q->execute([Password::hash('Platform-Test-Only-2026!')]);$admin=(int)$pdo->lastInsertId();
$app->settings()->update(['line_basic_id'=>'@legacytest','line_channel_access_token'=>'platform-legacy-token','line_channel_secret'=>'platform-legacy-secret'],$admin);
$identity=static fn(string $token):array=>match($token){'platform-token-a'=>['userId'=>'U'.str_repeat('e',32),'basicId'=>'@platform_a'],'platform-token-b'=>['userId'=>'U'.str_repeat('f',32),'basicId'=>'@platform_b'],default=>throw new RuntimeException('Unknown fixture token')};
$oas=new LineOfficialAccountService($app,$identity);(new ReflectionProperty($app,'lineOfficialAccounts'))->setValue($app,$oas);
$a=$oas->update(0,['channel_access_token'=>'platform-token-a','channel_secret'=>'platform-secret-a','enabled'=>true],$admin)['id'];
$b=0; // Distinct recipients use the same dormitory bot.
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$period=substr($today,0,7);
$room=$app->rooms()->create(['room_code'=>'PLATFORM-101','floor'=>1,'room_type'=>'LINE test','monthly_rent'=>'4500.00']);
$created=$app->bookings()->createAdminResident($admin,['room_id'=>$room['id'],'full_name'=>'ผู้พักทดสอบแพลตฟอร์ม','phone'=>'0815667788','move_in_date'=>$today,'opening_water_reading'=>'100.00','opening_electric_reading'=>'200.00','idempotency_key'=>'line-platform-resident']);$resident=(int)$created['resident_id'];
$users=['U'.str_repeat('1',32),'U'.str_repeat('2',32),'U'.str_repeat('3',32),'U'.str_repeat('4',32)];$codes=[];$replies=[];$sent=[];$failOnce=false;
$peer=new LineOfficialAccountService(new Dormitory\Application($app->config),$identity);
$transport=static function(string $body,string $retry,string $token)use(&$sent,&$failOnce,$peer,$expect):array{
    $expect(fn()=>$peer->withRegistryLock(static fn()=>true,0),'LINE_REGISTRY_BUSY');
    $sent[]=['body'=>$body,'retry'=>$retry,'token'=>$token];if($failOnce){$failOnce=false;throw new LineDeliveryException('Simulated transient LINE failure',true);}return ['status'=>200,'request_id'=>'fixture-request','accepted_request_id'=>null];
};
$notifications=new NotificationService($app,$transport);(new ReflectionProperty($app,'notifications'))->setValue($app,$notifications);
$replyTransport=static function(string $token,string $replyToken,string $text,int $deadline)use(&$replies,$peer,$expect):string{$expect(fn()=>$peer->withRegistryLock(static fn()=>true,0),'LINE_REGISTRY_BUSY');$replies[]=['token'=>$token,'text'=>$text];return 'replied';};
$eventCount=0;
$send=static function(int $oaId,string $text,string $user,?string $secret=null,?string $destination=null,?string $eventId=null)use($app,$oas,$replyTransport,&$eventCount):array{
    $oa=$oas->credentials($oaId);$eventId??='01'.str_pad((string)++$eventCount,24,'0',STR_PAD_LEFT);
    $raw=json_encode(['destination'=>$destination??$oa['provider_user_id'],'events'=>[['webhookEventId'=>$eventId,'mode'=>'active','type'=>'message','replyToken'=>'fixture_reply_'.str_pad((string)$eventCount,16,'0',STR_PAD_LEFT),'source'=>['type'=>'user','userId'=>$user],'message'=>['type'=>'text','text'=>$text]]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $request=new Request('POST',$oaId===0?'/api/webhooks/line':'/api/webhooks/line/oa/'.$oa['route_token'],['content-type'=>'application/json','x-line-signature'=>base64_encode(hash_hmac('sha256',$raw,$secret??$oa['channel_secret'],true))],[],[],[],['REMOTE_ADDR'=>'127.0.0.1'],'line-platform-test',$raw);
    return(new LineWebhookService($app,$replyTransport,$oaId))->handle($request);
};
$test('OA-scoped webhook checks signature and destination before consuming room codes',function()use($app,$oas,$resident,$admin,$a,$b,$users,$send,$expect,$assert,&$codes,&$replies):void{
    $codes[]=$app->lineRoomBindings()->issue($resident,['oa_id'=>$a],$admin);
    $expect(fn()=>$send($a,$codes[0]['code'],$users[0],'wrong-secret'),'LINE_WEBHOOK_SIGNATURE_INVALID');
    $expect(fn()=>$send($a,$codes[0]['code'],$users[0],null,'U'.str_repeat('0',32)),'LINE_DESTINATION_MISMATCH');
    $expect(fn()=>$oas->credentials(1),'LINE_SINGLE_BOT_ONLY');
    $assert($app->lineRoomBindings()->detail($resident)['bound_count']===0);
});
$test('multiple verified accounts bind one room and reply only through their own OA',function()use($app,$resident,$admin,$a,$b,$users,$send,$assert,&$codes,&$replies):void{
    $send($a,$codes[0]['code'],$users[0]);$assert(str_contains(end($replies)['text'],'PLATFORM-101'));$assert(end($replies)['token']==='platform-token-a');
    foreach([[$a,$users[1]],[$b,$users[2]]]as[$oa,$user]){$code=$app->lineRoomBindings()->issue($resident,['oa_id'=>$oa,'replace_pending'=>false],$admin);$codes[]=$code;$send($oa,$code['code'],$user);}
    $profile=$app->residents()->profile($resident);$assert($profile['line_verified']===true&&$profile['line_bound_count']===3);$assert(!isset($profile['pending_codes'],$profile['line_user_id']));
    $assert(count($app->notifications()->recipients($resident))===3);
});
$test('distinct events reach distinct recipients while redelivery is deduplicated',function()use($a,$b,$users,$send,$assert,&$replies):void{
    $id='01'.str_repeat('A',24);$before=count($replies);$send($a,'สถานะ',$users[0],null,null,$id);$send($b,'สถานะ',$users[2],null,null,'01'.str_repeat('B',24));$duplicate=$send($a,'สถานะ',$users[0],null,null,$id);
    $assert(count($replies)===$before+2&&$duplicate['duplicates']===1);
});
$app->billing()->updateSettings(['water_rate'=>'18.50','electric_rate'=>'7.25','due_days'=>7],$admin);
$app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'111.00','electric_current'=>'220.00'],$admin);
$input=['period'=>$period,'room_ids'=>[$room['id']],'due_date'=>$today,'confirm_current_period'=>true];$preview=$app->billing()->preview($input);$issued=$app->billing()->bulk($input+['preview_token'=>$preview['preview_token']],$admin);$bill=(int)$issued['created'][0]['id'];
$test('one bill fans out once per account and default changes cannot reroute recipients',function()use($notifications,$bill,$oas,$b,$admin,$pdo,$assert,$a):void{
    $oas->setDefault($b,$admin);$queued=$notifications->enqueueBill($bill);$assert($queued['recipient_count']===3);$notifications->enqueueBill($bill);
    $rows=$pdo->query('SELECT line_oa_id,line_binding_id FROM notification_outbox ORDER BY id')->fetchAll();$assert(count($rows)===3);
    $ids=array_count_values(array_column($rows,'line_oa_id'));$assert($ids[0]===3&&count($ids)===1);
});
$test('transient retries preserve the exact payload, recipient, OA token and retry UUID',function()use($notifications,$pdo,$assert,&$sent,&$failOnce):void{
    $failOnce=true;$result=$notifications->process(3);$assert($result['retried']===1&&$result['sent']===2);$first=$sent[0];
    $pdo->exec("UPDATE notification_outbox SET next_attempt_at=UTC_TIMESTAMP(6) WHERE status='pending'");$result=$notifications->process(1);$assert($result['sent']===1);$assert(end($sent)===$first,'Retry payload identity changed');
    $byRetry=[];foreach($sent as$attempt)$byRetry[$attempt['retry']]=$attempt;
    $assert(count($byRetry)===3&&count(array_filter($byRetry,fn($r)=>$r['token']==='platform-token-a'))===3);
});
$test('bill list contains one row and reports all delivery outcomes',function()use($app,$period,$assert):void{
    $rows=$app->billing()->adminList($period);$assert(count($rows)===1,'Outbox fanout duplicated bill rows');$assert(($rows[0]['line_delivery_counts']['sent']??null)===3);
});
$test('a newly linked account gets its own delivery without resending to existing recipients',function()use($app,$resident,$a,$admin,$users,$send,$notifications,$bill,$assert,&$sent,&$codes):void{
    $code=$app->lineRoomBindings()->issue($resident,['oa_id'=>$a],$admin);$codes[]=$code;$send($a,$code['code'],$users[3]);$queued=$notifications->enqueueBill($bill);$assert($queued['recipient_count']===4&&$queued['newly_queued']);$before=count($sent);$result=$notifications->process(1);$assert($result['sent']===1&&count($sent)===$before+1);
});
$test('admin claims require the correct OA, preserve active owner when a pending key is disabled, and validate mute values',function()use($app,$a,$b,$admin,$oas,$expect,$assert,&$owner,&$staff,&$claimCodes):void{
    $service=$app->lineAdminRecipients();$owner=$service->issue(['oa_id'=>$a,'label'=>'Owner test','is_owner'=>true],$admin);$claimCodes[]=$owner['code'];
    $expect(fn()=>$oas->withRegistryLock(fn()=>$service->consume($owner['code'],'U'.str_repeat('a',32),1)),'LINE_SINGLE_BOT_ONLY');
    $oas->withRegistryLock(fn()=>$service->consume($owner['code'],'U'.str_repeat('a',32),$a));
    $pending=$service->issue(['oa_id'=>$a,'label'=>'Disabled owner','is_owner'=>true],$admin);$claimCodes[]=$pending['code'];$service->update($pending['id'],['enabled'=>false],$admin);
    $expect(fn()=>$oas->withRegistryLock(fn()=>$service->consume($pending['code'],'U'.str_repeat('b',32),$a)),'LINE_RECIPIENT_DISABLED');
    $assert($service->get($owner['id'])['enabled']===true&&$service->get($owner['id'])['status']==='claimed');
    $expect(fn()=>$service->update($owner['id'],['muted_categories'=>[['payment']]],$admin),'VALIDATION_ERROR');
    $staff=$service->issue(['oa_id'=>$b,'label'=>'Staff test'],$admin);$claimCodes[]=$staff['code'];$oas->withRegistryLock(fn()=>$service->consume($staff['code'],'U'.str_repeat('c',32),$b));
});
$test('admin notices encrypt message bodies, deduplicate events, and recheck mutes before sending',function()use($app,$pdo,$admin,$owner,$staff,$assert,&$sent):void{
    $notice=$app->lineNotices();$before=(int)$pdo->query('SELECT COUNT(*) FROM line_notice_outbox')->fetchColumn();$message='มีสถานะบิลใหม่ กรุณาตรวจสอบในหน้าผู้ดูแล';
    $notice->enqueueAdmin('billing',$message,'integration-event-1');$notice->enqueueAdmin('billing',$message,'integration-event-1');$assert((int)$pdo->query('SELECT COUNT(*) FROM line_notice_outbox')->fetchColumn()===$before+2);
    $assert(!str_contains((string)$pdo->query('SELECT message_enc FROM line_notice_outbox ORDER BY id DESC LIMIT 1')->fetchColumn(),$message));
    $app->lineAdminRecipients()->update($owner['id'],['muted_categories'=>['billing']],$admin);$old=count($sent);$result=$notice->process(100);$assert($result['sent']===1&&$result['failed']===1);$assert(count($sent)===$old+1&&end($sent)['token']==='platform-token-a');
});
$test('queued account revocation and room blocking prevent later bill and command disclosure',function()use($app,$resident,$admin,$a,$users,$send,$pdo,$notifications,$assert,&$sent,&$replies,$codes):void{
    $pdo->exec("UPDATE notification_outbox SET status='pending',sent_at=NULL,line_request_id=NULL,line_accepted_request_id=NULL,next_attempt_at=UTC_TIMESTAMP(6),attempts=0 WHERE line_binding_id=".(int)$codes[0]['id']);
    $app->lineRoomBindings()->revokeAccount($resident,(int)$codes[0]['id'],$admin);$before=count($sent);$result=$notifications->process(1);$assert($result['failed']===1&&count($sent)===$before);
    $send($a,'บิล',$users[0]);$assert(!str_contains(end($replies)['text'],'PLATFORM-101'));
    $app->lineRoomBindings()->block($resident,'ทดสอบระงับบัญชี',$admin);$assert($notifications->recipients($resident)===[]);$send($a,'สถานะ',$users[1]);$assert(!str_contains(end($replies)['text'],'PLATFORM-101'));
    $app->lineRoomBindings()->unblock($resident,$admin);$assert($notifications->recipients($resident)===[],'Unblock silently restored revoked accounts');
});
$test('compatibility unlink and access reissue invalidate every new account and pending code',function()use($app,$resident,$admin,$a,$users,$assert,$notifications,&$codes):void{
    $service=$app->lineRoomBindings();$code=$service->issue($resident,['oa_id'=>$a],$admin);$codes[]=$code;$service->consume($code['code'],$users[0],$a);
    $notifications->withLineBindingLock($resident,fn()=>$app->database()->transaction(fn()=>$app->residents()->unlinkLine($resident,[])));
    $assert($notifications->recipients($resident)===[]&&$app->residents()->profile($resident)['line_verified']===false);
    $code=$service->issue($resident,['oa_id'=>$a],$admin);$codes[]=$code;$service->consume($code['code'],$users[0],$a);$pending=$service->issue($resident,['oa_id'=>$a,'replace_pending'=>false],$admin);$codes[]=$pending;
    $notifications->withLineBindingLock($resident,fn()=>$app->residents()->reissueAccess($resident));$detail=$service->detail($resident);$assert($detail['bound_count']===0&&$detail['pending_codes']===[]);
});
$test('strict audit metadata contains neither invitation codes nor raw LINE recipients or tokens',function()use($pdo,$assert,$codes,$claimCodes,$users):void{
    $json=implode('',array_column($pdo->query('SELECT details FROM audit_logs')->fetchAll(),'details'));
    foreach(array_merge(array_column($codes,'code'),$claimCodes,$users,['platform-token-a','platform-token-b'])as$secret)$assert(!str_contains($json,$secret),'Audit leaked a LINE secret');
});
echo "{$passed} LINE platform integration groups passed; provider calls were simulated in process.\n";
