<?php
declare(strict_types=1);

use Dormitory\Domain\LineBotService;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;

if (PHP_SAPI !== 'cli' || getenv('APP_ENV')!=='testing'
    || preg_match('/^appj_line_test_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1) {
    fwrite(STDERR,"Dedicated LINE testing database required\n");exit(64);
}
$app=require dirname(__DIR__).'/bootstrap.php';
$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','residents','bills','bookings','line_link_codes'] as $table) {
    if ((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0) throw new RuntimeException('Fixture must be empty');
}
$assert=static function(bool $condition,string $why='Assertion failed'):void {if(!$condition)throw new RuntimeException($why);};
$passed=0;
$check=static function(string $name,callable $test)use(&$passed):void{$test();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$statement=$pdo->prepare("INSERT INTO admin_users (username,password_hash,role,active,auth_version,created_at,updated_at)
    VALUES ('line_test_owner',?,'owner',1,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
$statement->execute([Password::hash('LINE-Local-Test-Only-2026!')]);$ownerId=(int)$pdo->lastInsertId();
$app->settings()->update(['line_basic_id'=>'@linefixture','line_channel_access_token'=>'line-local-fixture-token-2026',
    'line_channel_secret'=>'line-local-fixture-secret-2026'],$ownerId);
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$period=substr($today,0,7);
$room=$app->rooms()->create(['room_code'=>'LINE-101','floor'=>1,'room_type'=>'ทดสอบ','monthly_rent'=>'4500.00']);
$resident=$app->bookings()->createAdminResident($ownerId,['room_id'=>$room['id'],'full_name'=>'ผู้พัก ทดสอบไลน์',
    'phone'=>'0815556677','move_in_date'=>$today,'opening_water_reading'=>'100.00','opening_electric_reading'=>'200.00',
    'idempotency_key'=>'line-bot-fixture-20260915']);
$residentId=(int)$resident['resident_id'];$sender='U'.str_repeat('a',32);$outsider='U'.str_repeat('b',32);
$bindingLock='dormitory:line:'.substr(hash_hmac('sha256',(string)$residentId,$app->config->appKey()),0,32);
// A separate connection represents an unlink/profile/move-out request. It
// must not acquire the resident fence while private data is being delivered.
$probe=(new Dormitory\Database($app->config))->pdo();$privateReplies=0;
$assertLockReleased=static function()use($probe,$bindingLock,$assert):void{
    $acquire=$probe->prepare('SELECT GET_LOCK(?,0)');$acquire->execute([$bindingLock]);
    $acquired=(int)$acquire->fetchColumn();
    if($acquired===1){$release=$probe->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$bindingLock]);}
    $assert($acquired===1,'Private reply leaked its resident binding lock');
};
$replies=[];$transport=static function(string $token,string $replyToken,string $text,int $deadline)use(&$replies,&$privateReplies,$probe,$bindingLock,$app,$assert):string{
    if(str_contains($text,'ผู้พัก ทดสอบไลน์')||str_contains($text,'LINE-101')){
        $acquire=$probe->prepare('SELECT GET_LOCK(?,0)');$acquire->execute([$bindingLock]);
        $acquired=(int)$acquire->fetchColumn();
        if($acquired===1){$release=$probe->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$bindingLock]);}
        $assert($acquired===0,'Concurrent unlink can commit during a private LINE reply');
        $assert(!$app->database()->pdo()->inTransaction(),'Private provider call must not hold a database transaction');
        $privateReplies++;
    }
    $replies[]=$text;return 'replied';
};
$webhook=new LineWebhookService($app,$transport);$eventCounter=0;
$event=static function(string $text,string $user)use(&$eventCounter):array{
    return ['webhookEventId'=>'01LINE'.str_pad((string)++$eventCounter,20,'0',STR_PAD_LEFT),
        'mode'=>'active','type'=>'message','timestamp'=>(int)(microtime(true)*1000),
        'replyToken'=>'fixture_reply_'.str_pad((string)$eventCounter,16,'0',STR_PAD_LEFT),
        'source'=>['type'=>'user','userId'=>$user],'message'=>['type'=>'text','id'=>(string)$eventCounter,'text'=>$text]];
};
// ULID excludes I/L/O/U; use a fixed valid prefix for these deterministic events.
$send=static function(array $events,bool $validSignature=true)use($webhook):array{
    foreach($events as &$event)$event['webhookEventId']=str_replace('01LINE','01ABCD',$event['webhookEventId']);unset($event);
    $raw=json_encode(['events'=>$events],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $signature=base64_encode(hash_hmac('sha256',$raw,$validSignature?'line-local-fixture-secret-2026':'wrong-secret',true));
    return $webhook->handle(new Request('POST','/api/webhooks/line',[
        'content-type'=>'application/json','x-line-signature'=>$signature],[],[],[],['REMOTE_ADDR'=>'127.0.0.1'],
        'line-bot-integration',$raw));
};
$code=$app->lineBindings()->issueForAdmin($residentId);
$check('generated code has a matching direct LINE chat link',static function()use($assert,$code):void{
    $assert($code['line_message_url']==='https://line.me/R/oaMessage/%40linefixture/?'.$code['code']);
});
$check('bad signature cannot consume a valid code',static function()use($assert,$send,$event,$code,$sender,$app,$residentId):void{
    try{$send([$event($code['code'],$sender)],false);throw new RuntimeException('Invalid signature accepted');}
    catch(HttpException $e){$assert($e->errorCode==='LINE_WEBHOOK_SIGNATURE_INVALID');}
    $assert($app->residents()->profile($residentId)['line_verified']===false);
});
$replacement=$app->lineBindings()->issueForAdmin($residentId);
$check('a rotated code is rejected without revealing the resident',static function()use($assert,$send,$event,$code,$outsider,&$replies):void{
    $send([$event($code['code'],$outsider)]);$text=end($replies);
    $assert(str_contains($text,'ไม่ถูกต้องหรือหมดอายุ'));$assert(!str_contains($text,'LINE-101'));
});
$bindEvent=$event(strtolower($replacement['code']),$sender);
$check('signed private message binds atomically and replies with the correct name and room',static function()use($assert,$send,$bindEvent,&$replies,$app,$residentId):void{
    $result=$send([$bindEvent]);$assert($result['replied']===1);$text=end($replies);
    $assert(str_contains($text,'ผู้พัก ทดสอบไลน์'));$assert(str_contains($text,'LINE-101'));
    $assert($app->residents()->profile($residentId)['line_verified']===true);
});
$check('redelivery does not bind or reply twice',static function()use($assert,$send,$bindEvent,&$replies):void{
    $count=count($replies);$result=$send([$bindEvent]);$assert($result['duplicates']===1);$assert(count($replies)===$count);
});
$check('same code and sender can recover the existing binding result',static function()use($assert,$send,$event,$replacement,$sender,&$replies):void{
    $send([$event($replacement['code'],$sender)]);$assert(str_contains(end($replies),'ไม่ต้องดำเนินการซ้ำ'));
});
$check('a different sender cannot take a consumed code or read its room',static function()use($assert,$send,$event,$replacement,$outsider,&$replies):void{
    $send([$event($replacement['code'],$outsider)]);$assert(!str_contains(end($replies),'LINE-101'));
});
$check('help, status and bills have independent command allowance after binding',static function()use($assert,$send,$event,$sender,&$replies):void{
    $send([$event('เมนู',$sender)]);$assert(str_contains(end($replies),'เมนูผู้พัก'));
    $send([$event('ＳＴＡＴＵＳ',$sender)]);$assert(str_contains(end($replies),'LINE-101'));
    $send([$event('bills',$sender)]);$assert(str_contains(end($replies),'ยังไม่มีบิล'));
});
$check('unbound users receive instructions without resident data',static function()use($assert,$send,$event,$outsider,&$replies):void{
    $send([$event('สถานะ',$outsider)]);$assert(str_contains(end($replies),'ยังไม่พบการผูก'));
    $assert(!str_contains(end($replies),'LINE-101'));
});
$check('group chat and ordinary conversation stay silent',static function()use($assert,$send,$event,$sender,&$replies):void{
    $group=$event('บิล',$sender);$group['source']['type']='group';
    $count=count($replies);$result=$send([$group,$event('ขอสอบถามเจ้าหน้าที่ค่ะ',$sender)]);
    $assert($result['skipped']===2);$assert(count($replies)===$count);
});
$app->billing()->updateSettings(['water_rate'=>'18.50','electric_rate'=>'7.25','due_days'=>7],$ownerId);
$app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'111.00','electric_current'=>'220.00'],$ownerId);
$billing=['period'=>$period,'room_ids'=>[$room['id']],'due_date'=>$today,'confirm_current_period'=>true];
$preview=$app->billing()->preview($billing);$issued=$app->billing()->bulk($billing+['preview_token'=>$preview['preview_token']],$ownerId);
$billId=(int)$issued['created'][0]['id'];
$check('bills command shows the persisted amount and authenticated portal link',static function()use($assert,$send,$event,$sender,&$replies):void{
    $send([$event('บิล',$sender)]);$text=end($replies);$assert(str_contains($text,'4,848.50 บาท'));
    $assert(str_contains($text,'/resident#bills'));$assert(!str_contains($text,'?token='));
});
$payment=$pdo->prepare("INSERT INTO payments(bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac,verification_attempts,created_at,updated_at)
    VALUES (?,?,?,'pending','tests/offline-line-slip.png','image/png',?,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
$payment->execute([$billId,$residentId,'4848.50',str_repeat('c',64)]);
$check('pending slips tell the resident not to transfer again',static function()use($assert,$send,$event,$sender,&$replies):void{
    $send([$event('invoice',$sender)]);$assert(str_contains(end($replies),'อย่าโอนซ้ำ'));
});
$check('raw binding secrets and LINE identities never appear in audit details',static function()use($assert,$pdo,$sender,$replacement):void{
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE details LIKE ? OR details LIKE ?');
    $q->execute(['%'.$sender.'%','%'.$replacement['code'].'%']);$assert((int)$q->fetchColumn()===0);
});
$check('private bind, status and bill transports hold and release the binding fence',static function()use($assert,&$privateReplies,$assertLockReleased):void{
    $assert($privateReplies>=6,'Expected private bind/replay/status/bill responses were not checked');
    $assertLockReleased();
});
$bot=new LineBotService($app);$prepared=[];
foreach(['status','bills','bound'] as $intent)$prepared[$intent]=$bot->command($sender,$intent);
$dispatch=new ReflectionMethod(LineWebhookService::class,'withCurrentReply');
$check('private reply fence is released when the provider fails',static function()use($assert,$dispatch,$webhook,$sender,$prepared,$assertLockReleased):void{
    try{
        $dispatch->invoke($webhook,['line_user_id'=>$sender],$prepared['bills'],hrtime(true)+8_000_000_000,
            static function(array $reply):string{throw new RuntimeException('Simulated LINE transport failure');});
        throw new RuntimeException('Transport failure was swallowed');
    }catch(RuntimeException $error){$assert($error->getMessage()==='Simulated LINE transport failure');}
    $assertLockReleased();
});
$check('expired private reply budget defers without calling the provider',static function()use($assert,$dispatch,$webhook,$sender,$prepared,$assertLockReleased):void{
    $called=false;
    $result=$dispatch->invoke($webhook,['line_user_id'=>$sender],$prepared['bills'],hrtime(true)+100_000_000,
        static function(array $reply)use(&$called):string{$called=true;return 'replied';});
    $assert($result==='deferred'&&!$called,'An exhausted webhook budget still sent a private reply');
    $assertLockReleased();
});
$app->notifications()->withLineBindingLock($residentId,fn()=>$app->database()->transaction(function()use($app,$residentId):void{
    $app->residents()->unlinkLine($residentId,[]);$app->lineBindings()->revokePending($residentId);
}));
$check('unlink winning after reply preparation removes all private data before send',static function()use($assert,$dispatch,$webhook,$sender,$prepared,$assertLockReleased):void{
    // This deterministic interleaving reproduces the former TOCTOU window:
    // command reads private data, another request commits unlink, then send.
    foreach($prepared as $reply){
        $assert(str_contains($reply['text'],'ผู้พัก ทดสอบไลน์'),'Fixture did not prepare private data');
        $sent=null;
        $result=$dispatch->invoke($webhook,['line_user_id'=>$sender],$reply,hrtime(true)+8_000_000_000,
            static function(array $current)use(&$sent):string{$sent=$current;return 'replied';});
        $assert($result==='replied'&&$sent['outcome']==='unbound','Revoked private reply was not revalidated');
        foreach(['ผู้พัก ทดสอบไลน์','LINE-101','4,848.50'] as $private)$assert(!str_contains($sent['text'],$private),'Revoked LINE received stale private data');
        $assertLockReleased();
    }
});
$check('unlinked accounts immediately lose status and bill access',static function()use($assert,$send,$event,$sender,&$replies):void{
    $send([$event('บิล',$sender)]);$assert(str_contains(end($replies),'ยังไม่พบการผูก'));$assert(!str_contains(end($replies),'4,848.50'));
});
fwrite(STDOUT,"{$passed} MySQL LINE bot integration groups passed (provider replies simulated; no external requests)\n");
