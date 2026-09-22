<?php
declare(strict_types=1);

use Dormitory\Domain\LineBotService;
use Dormitory\Domain\LineDeliveryException;
use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Domain\LineWebhookService;
use Dormitory\Domain\NotificationService;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Http\Routes;
use Dormitory\Security\Password;

if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^appj_(?:line_test_)?line_qr(?:_[a-z0-9_]+)?$/D',(string)getenv('DB_DATABASE')))exit(64);
putenv('APP_URL=https://line-qr.example.test');putenv('FORCE_HTTPS=false');
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','residents','bills','transfer_instructions']as$table)if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0)throw new RuntimeException('Fresh isolated LINE QR database required');
$passed=0;
$assert=static function(bool $ok,string $why='Assertion failed'):void{if(!$ok)throw new RuntimeException($why);};
$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$denied=static function(callable $fn)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->status===404&&$e->errorCode==='LINE_QR_UNAVAILABLE');return;}throw new RuntimeException('Expected unavailable QR');};
$rollback=static function(callable $fn)use($pdo):void{$pdo->beginTransaction();try{$fn();}finally{if($pdo->inTransaction())$pdo->rollBack();}};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('line_qr_owner',?,'owner',1,1)");$q->execute([Password::hash('Offline-QR-Fixture-2026!')]);$owner=(int)$pdo->lastInsertId();
$registry=new LineOfficialAccountService($app,static fn(string $token):array=>['userId'=>'U'.str_repeat('9',32),'basicId'=>'@qr_fixture','displayName'=>'QR fixture']);
(new ReflectionProperty($app,'lineOfficialAccounts'))->setValue($app,$registry);
$registry->update(0,['channel_access_token'=>'offline-qr-token','channel_secret'=>'offline-qr-secret','enabled'=>true],$owner);
$app->settings()->update(['promptpay_target'=>'0812345678','promptpay_name'=>'Offline receiver','slip_provider'=>'none'],$owner);
$app->billing()->updateSettings(['water_rate'=>'0','electric_rate'=>'0','due_days'=>7],$owner);
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$period=substr($today,0,7);$n=0;
$make=function()use($app,$owner,$today,$period,&$n):array{
    $n++;$room=$app->rooms()->create(['room_code'=>'LINE-QR-'.$n,'floor'=>1,'room_type'=>'Fixture','monthly_rent'=>'100.00']);
    $resident=$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'QR Resident '.$n,'phone'=>'088710'.str_pad((string)$n,4,'0',STR_PAD_LEFT),'move_in_date'=>$today,'opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'line-payment-qr-fixture-'.$n]);
    $id=(int)$resident['resident_id'];$user='U'.str_pad(dechex($n),32,'0',STR_PAD_LEFT);
    $code=$app->lineRoomBindings()->issue($id,[],$owner);$app->lineRoomBindings()->consume($code['code'],$user,0);
    $app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner);
    $input=['period'=>$period,'room_ids'=>[$room['id']],'confirm_current_period'=>true];$preview=$app->billing()->preview($input);
    $bill=$app->billing()->bulk($input+['preview_token'=>$preview['preview_token']],$owner)['created'][0];
    return ['bill'=>(int)$bill['id'],'resident'=>$id,'room'=>(int)$room['id'],'user'=>$user,'target'=>$app->lineBills()->recipient($id,0,$user)];
};
$a=$make();$b=$make();$cards=[];$queued=[];
$tokenOf=static function(array $card):string{parse_str((string)parse_url($card['contents']['hero']['url'],PHP_URL_QUERY),$query);return $query['token'];};
$stateOf=static function(int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM notification_outbox WHERE id=?');$q->execute([$id]);return $q->fetch();};
$test('LINE bill enqueue reserves exact QR amounts without a slip provider',function()use($app,$assert,$a,$b,&$cards,&$queued,$stateOf):void{
    $assert(!$app->settings()->publicSettings()['slip_verification_ready']);
    foreach([$a,$b]as$f){$queued[$f['bill']]=$app->notifications()->enqueueBill($f['bill']);$row=$stateOf($queued[$f['bill']]['id']);$payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);$assert(count($payload['messages'])===2);$cards[$f['bill']]=$payload['messages'][1];$assert($cards[$f['bill']]['type']==='flex');$assert($payload['to']===$f['user']);}
    $one=$app->transfers()->find($a['bill']);$two=$app->transfers()->find($b['bill']);$assert($one['transfer_amount']!==$two['transfer_amount']&&$one['bill_amount']==='100.00');
});
$test('browser reservation and LINE card share one immutable amount and recipient',function()use($app,$a,$cards,$assert):void{
    $stored=$app->transfers()->find($a['bill']);$web=$app->transfers()->reserve($a['bill'],$a['resident']);$assert($stored===$web);
    $json=json_encode($cards[$a['bill']],JSON_UNESCAPED_UNICODE);$assert(str_contains($json,$web['transfer_amount'].' บาท')&&str_contains($json,'100.00 บาท'));
    $app->lineBills()->assertStoredCard($cards[$a['bill']],$a['bill'],$a['target']);
});
$test('signed image is a deterministic PNG; repeated reads never allocate or change invoice principal',function()use($app,$pdo,$a,$cards,$tokenOf,$assert):void{
    $before=$pdo->query('SELECT COUNT(*) FROM transfer_instructions')->fetchColumn();$token=$tokenOf($cards[$a['bill']]);
    $png=$app->lineBills()->image($a['bill'],$token);$assert(str_starts_with($png,"\x89PNG\r\n\x1a\n"));$assert($png===$app->lineBills()->image($a['bill'],$token));
    $assert($before===$pdo->query('SELECT COUNT(*) FROM transfer_instructions')->fetchColumn());
    $assert($pdo->query('SELECT total_amount FROM bills WHERE id='.$a['bill'])->fetchColumn()==='100.00');
    // Optional local decoding evidence; never enabled by deployed runtime or CI.
    if(($dir=getenv('LINE_QR_TEST_ARTIFACT_DIR'))&&is_dir($dir)){
        file_put_contents($dir.'/line-payment-qr.png',$png);
        $i=$app->transfers()->find($a['bill']);file_put_contents($dir.'/line-payment-qr-payload.txt',\Dormitory\Domain\PromptPayService::payload($i['promptpay_target'],$i['transfer_amount']));
        file_put_contents($dir.'/line-payment-flex.json',json_encode($cards[$a['bill']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
    }
});
$test('invalid, tampered, cross-bill, expired and overlong tokens reveal no QR',function()use($app,$a,$b,$cards,$tokenOf,$denied):void{
    $token=$tokenOf($cards[$a['bill']]);
    foreach([null,[],str_repeat('a',1000),'',substr($token,0,-1).(str_ends_with($token,'a')?'b':'a')]as$bad)$denied(fn()=>$app->lineBills()->image($a['bill'],$bad));
    $denied(fn()=>$app->lineBills()->image($b['bill'],$token));
    $parts=explode('.',$token);$parts[8]=(string)(time()-1);array_pop($parts);$unsigned=implode('.',$parts);$expired=$unsigned.'.'.hash_hmac('sha256',"line-payment-image-v1\n".$unsigned,$app->config->appKey());
    $denied(fn()=>$app->lineBills()->image($a['bill'],$expired));
});
$test('public image route requires no login but denies missing token and disables caching',function()use($app,$a,$cards,$tokenOf,$assert):void{
    $route=Routes::build($app);$path='/api/public/line-payment-qr/'.$a['bill'];
    $request=static fn(array $query):Request=>new Request('GET',$path,[],$query,[],[],['REMOTE_ADDR'=>'127.0.0.82'],'line-qr-http');
    $ok=$route->dispatch($request(['token'=>$tokenOf($cards[$a['bill']])]));$assert($ok->status===200&&$ok->headers['Content-Type']==='image/png'&&str_contains($ok->headers['Cache-Control'],'no-store'));
    $assert($route->dispatch($request([]))->status===404);
});
$calls=[];$fail=true;
$notify=new NotificationService($app,static function(string $body,string $key,string $token)use(&$calls,&$fail):array{
    $calls[]=[$body,$key,$token];if($fail)throw new LineDeliveryException('Offline timeout',true);
    return ['request_id'=>'offline-request','accepted_request_id'=>'offline-accepted','status'=>409];
});
$process=static fn(int $limit=1):array=>(new ReflectionMethod($notify,'processBills'))->invoke($notify,$limit);
$test('QR push timeout retries the original body URL amount recipient and retry key',function()use($app,$pdo,$a,$queued,$stateOf,$process,&$calls,&$fail,$assert):void{
    $id=$queued[$a['bill']]['id'];$before=$stateOf($id);$result=$process();$assert($result['retried']===1&&count($calls)===1);
    $pdo->exec('UPDATE notification_outbox SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id='.$id);$fail=false;$result=$process();
    $assert($result['sent']===1&&count($calls)===2&&$calls[0]===$calls[1]);$after=$stateOf($id);
    $assert($before['payload']===$after['payload']&&$before['retry_key']===$after['retry_key']);
    $assert($app->notifications()->enqueueBill($a['bill'])['enqueue_state']==='already_sent');
});
$test('out-of-band receiver corruption after queue prevents pushing or opening the old QR',function()use($app,$pdo,$b,$cards,$tokenOf,$denied,$rollback,$process,&$calls,$assert):void{
    $rollback(function()use($app,$pdo,$b,$cards,$tokenOf,$denied,$process,&$calls,$assert):void{
        $pdo->exec("UPDATE integration_settings SET promptpay_target='0898765432' WHERE id=1");$denied(fn()=>$app->lineBills()->image($b['bill'],$tokenOf($cards[$b['bill']])));
        $before=count($calls);$result=$process();$assert($result['failed']===1&&count($calls)===$before);
    });
});
$test('changed recipient or foreign image URL cannot be substituted into a queued card',function()use($app,$a,$b,$cards,$denied):void{
    $denied(fn()=>$app->lineBills()->assertStoredCard($cards[$a['bill']],$a['bill'],$b['target']));
    $bad=$cards[$a['bill']];$bad['contents']['hero']['url']='https://attacker.invalid/qr.png';$denied(fn()=>$app->lineBills()->assertStoredCard($bad,$a['bill'],$a['target']));
    $bad=$cards[$a['bill']];$bad['contents']['footer']['contents'][0]['action']['uri']='https://attacker.invalid/pay';$denied(fn()=>$app->lineBills()->assertStoredCard($bad,$a['bill'],$a['target']));
});
$test('unlinked, blocked or disabled bot cannot serve a previously issued image',function()use($app,$registry,$owner,$a,$cards,$tokenOf,$rollback,$denied):void{
    foreach(['unlink','block','oa']as$mode)$rollback(function()use($app,$registry,$owner,$a,$cards,$tokenOf,$denied,$mode):void{
        if($mode==='unlink')$app->lineRoomBindings()->revokeAccount($a['resident'],$a['target']['id'],$owner);
        elseif($mode==='block')$app->lineRoomBindings()->block($a['resident'],'Offline test',$owner);
        else $registry->update(0,['enabled'=>false],$owner);
        $denied(fn()=>$app->lineBills()->image($a['bill'],$tokenOf($cards[$a['bill']])));
    });
});
$test('changing resident phone invalidates old QR capabilities',function()use($app,$a,$cards,$tokenOf,$rollback,$denied):void{
    $rollback(function()use($app,$a,$cards,$tokenOf,$denied):void{$app->residents()->updateByAdmin($a['resident'],['phone'=>'0887999999']);$denied(fn()=>$app->lineBills()->image($a['bill'],$tokenOf($cards[$a['bill']])));});
});
$test('bill command returns rich QR only for the verified resident and correct OA',function()use($app,$a,$assert,$cards,$tokenOf):void{
    $bot=new LineBotService($app,0);$reply=$bot->command($a['user'],'bills');$assert(count($reply['messages'])===2&&$reply['messages'][1]['type']==='flex');
    $assert($app->lineBills()->image($a['bill'],$tokenOf($cards[$a['bill']]))===$app->lineBills()->image($a['bill'],$tokenOf($reply['messages'][1])));
    $assert($bot->command('U'.str_repeat('f',32),'bills')['outcome']==='unbound');$assert((new LineBotService($app,99))->command($a['user'],'bills')['outcome']==='unbound');
});
$test('signed webhook transports QR cards with text and ignores duplicate event delivery',function()use($app,$a,$assert):void{
    $messages=[];$hook=new LineWebhookService($app,static function(string $token,string $replyToken,string $text,int $deadline,array $sent)use(&$messages):string{$messages[]=$sent;return 'replied';});
    $raw=json_encode(['destination'=>'U'.str_repeat('9',32),'events'=>[['webhookEventId'=>'01ARZ3NDEKTSV4RRFFQ69G5FAV','mode'=>'active','type'=>'message','timestamp'=>(int)(microtime(true)*1000),'replyToken'=>str_repeat('x',32),'source'=>['type'=>'user','userId'=>$a['user']],'message'=>['type'=>'text','id'=>'777','text'=>'บิล']]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $request=new Request('POST','/api/webhooks/line',['content-type'=>'application/json','x-line-signature'=>base64_encode(hash_hmac('sha256',$raw,'offline-qr-secret',true))],[],[],[],['REMOTE_ADDR'=>'127.0.0.82'],'qr-webhook',$raw);
    $assert($hook->handle($request)['replied']===1);$assert(count($messages)===1&&count($messages[0])===2&&$messages[0][1]['type']==='flex');
    $assert($hook->handle($request)['duplicates']===1&&count($messages)===1);
});
$test('pending or verified evidence and paid bills suppress both image and bot QR',function()use($app,$pdo,$a,$cards,$tokenOf,$rollback,$denied,$assert):void{
    $rollback(function()use($app,$pdo,$a,$cards,$tokenOf,$denied,$assert):void{
        $q=$pdo->prepare("INSERT INTO payments(bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac,verification_attempts) VALUES(?,?,100,'pending','tests/offline-qr-slip.png','image/png',?,1)");$q->execute([$a['bill'],$a['resident'],str_repeat('e',64)]);$payment=(int)$pdo->lastInsertId();
        foreach(['pending','verified','paid']as$stage){
            if($stage==='verified')$pdo->exec("UPDATE payments SET status='verified',provider='offline',transaction_ref='OFFLINE-LINE-QR',receiver_ref='offline-receiver',provider_payload=JSON_OBJECT('offline',TRUE),verified_at=UTC_TIMESTAMP(6) WHERE id=".$payment);
            if($stage==='paid')$pdo->exec("UPDATE bills SET status='paid',paid_at=UTC_TIMESTAMP(6) WHERE id=".$a['bill']);
            $denied(fn()=>$app->lineBills()->image($a['bill'],$tokenOf($cards[$a['bill']])));
            $reply=(new LineBotService($app))->command($a['user'],'bills');$assert(count($reply['messages'])===1);
        }
    });
});
$test('HTTP-only deployment sends safe text fallback without allocating a new QR',function()use($app,$make,$assert,$pdo):void{
    $f=$make();putenv('APP_URL=http://localhost');
    try{$item=$app->notifications()->enqueueBill($f['bill']);$q=$pdo->prepare('SELECT payload FROM notification_outbox WHERE id=?');$q->execute([$item['id']]);$payload=json_decode($q->fetchColumn(),true);$assert(count($payload['messages'])===1&&$app->transfers()->find($f['bill'])===null);}
    finally{putenv('APP_URL=https://line-qr.example.test');}
});
$test('webhook QR lock contention returns text fallback promptly and restores connection settings',function()use($app,$a,$pdo,$assert):void{
    $peer=(new \Dormitory\Database($app->config))->pdo();$before=$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
    $peer->beginTransaction();$peer->query('SELECT id FROM bills WHERE id='.$a['bill'].' FOR UPDATE');
    try{
        $start=hrtime(true);$card=$app->lineBills()->paymentCard($a['bill'],$a['resident'],$a['target'],$start+8_000_000_000);
        $assert($card===null&&(hrtime(true)-$start)<2_500_000_000,'QR allocation exceeded bounded webhook wait');
        $assert(!$pdo->inTransaction()&&$before===$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn());
    }finally{$peer->rollBack();}
    $assert($app->lineBills()->paymentCard($a['bill'],$a['resident'],$a['target'],hrtime(true)+100_000_000)===null);
});
$test('real HTTP image response works without cookies and rejects invalid capabilities',function()use($app,$a,$cards,$tokenOf,$assert):void{
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$socket)throw new RuntimeException('Cannot allocate loopback test port');
    $address=stream_socket_get_name($socket,false);fclose($socket);$log=tempnam(sys_get_temp_dir(),'line-qr-http-');
    $root=dirname(__DIR__);$server=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/public/index.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['redirect',1]],$pipes,$root,null,['bypass_shell'=>true]);
    if(!is_resource($server))throw new RuntimeException('Cannot start HTTP fixture');fclose($pipes[0]);
    try{
        for($i=0;$i<40;$i++){$ready=@stream_socket_client('tcp://'.$address,$errno,$message,0.1);if($ready){fclose($ready);break;}usleep(50000);}
        $path='/api/public/line-payment-qr/'.$a['bill'];$curl=curl_init('http://'.$address.$path.'?token='.$tokenOf($cards[$a['bill']]));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);
        $assert(is_string($raw)&&$status===200,'Real image HTTP status was '.$status);$headers=substr($raw,0,$size);$body=substr($raw,$size);
        $assert(str_contains(strtolower($headers),'content-type: image/png')&&!str_contains(strtolower($headers),'set-cookie:'));
        $assert(str_contains(strtolower($headers),'referrer-policy: no-referrer')&&str_contains(strtolower($headers),'cross-origin-resource-policy: cross-origin'));
        $assert($body===$app->lineBills()->image($a['bill'],$tokenOf($cards[$a['bill']])));
        curl_setopt($curl,CURLOPT_URL,'http://'.$address.$path.'?token=invalid');curl_exec($curl);$assert((int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===404);
    }finally{proc_terminate($server);proc_close($server);if(is_file($log))unlink($log);}
});
$quiet=static function()use($pdo):void{$pdo->exec("UPDATE notification_outbox SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE status='pending'");};
$test('receiver changes and clearing are atomic conflicts while reserved bills exist',function()use($app,$pdo,$owner,$a,$assert,$rollback):void{
    $rollback(function()use($app,$pdo,$owner,$a,$assert):void{
        $before=$app->transfers()->find($a['bill']);
        foreach(['0898765432',null]as$target){
            try{$app->settings()->update(['promptpay_target'=>$target,'promptpay_name'=>'Must not save'],$owner);throw new RuntimeException('Unsafe account change accepted');}
            catch(HttpException $e){$assert($e->status===409&&$e->errorCode==='PROMPTPAY_HAS_RESERVED_BILLS'&&in_array($a['bill'],$e->details['bill_ids'],true));}
            $assert($app->settings()->value('promptpay_target')==='0812345678'&&$app->settings()->value('promptpay_name')==='Offline receiver');
        }
        $app->settings()->update(['promptpay_target'=>'0812345678','promptpay_name'=>'Updated display name'],$owner);
        $assert($app->transfers()->reserve($a['bill'],$a['resident'])===$before);
        // Repair an out-of-band bad configuration only when all reservations
        // actually belong to the restored target; never rotate instructions.
        $pdo->exec("UPDATE integration_settings SET promptpay_target='0898765432' WHERE id=1");
        $app->settings()->update(['promptpay_target'=>'0812345678'],$owner);
        $assert($app->transfers()->reserve($a['bill'],$a['resident'])===$before);
    });
});
$test('signed image and prefilled payment text reach guidance, dedupe and preserve payment state',function()use($app,$a,$assert,$rollback):void{
    $rollback(function()use($app,$a,$assert):void{
        $sent=[];$hook=new LineWebhookService($app,static function(string $token,string $replyToken,string $text,int $deadline,array $messages)use(&$sent):string{$sent[]=$messages;return 'replied';});
        $events=[];foreach([['type'=>'image','id'=>'778'],['type'=>'text','id'=>'779','text'=>"แจ้งชำระ BILL-TEST\nห้อง TEST"],['type'=>'text','id'=>'780','text'=>'คุยกับผู้ดูแล']]as$i=>$message){
            $events[]=['webhookEventId'=>'01ARZ3NDEKTSV4RRFFQ69G5FA'.['W','X','Y'][$i],'mode'=>'active','type'=>'message','replyToken'=>str_repeat('g',32),'source'=>['type'=>'user','userId'=>$a['user']],'message'=>$message];
        }
        $raw=json_encode(['destination'=>'U'.str_repeat('9',32),'events'=>$events],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $request=new Request('POST','/api/webhooks/line',['content-type'=>'application/json','x-line-signature'=>base64_encode(hash_hmac('sha256',$raw,'offline-qr-secret',true))],[],[],[],['REMOTE_ADDR'=>'127.0.0.84'],'qr-guidance',$raw);
        $result=$hook->handle($request);$assert($result['replied']===2&&$result['skipped']===1&&count($sent)===2);
        foreach($sent as$messages)$assert(count($messages)===1&&str_contains($messages[0]['text'],'เว็บยังไม่ได้รับไฟล์')&&str_contains($messages[0]['text'],'ไม่ต้องโอนซ้ำ'));
        $assert($hook->handle($request)['duplicates']===2&&count($sent)===2);
        $assert($app->billing()->residentDetail($a['resident'],$a['bill'])['payment']===null);
    });
});
$test('first-claim QR preflight rejection can safely recover after APP_URL correction',function()use($app,$pdo,$rollback,$quiet,$make,$assert,$stateOf):void{
    $rollback(function()use($app,$pdo,$quiet,$make,$assert,$stateOf):void{
        $quiet();$f=$make();$job=$app->notifications()->enqueueBill($f['bill']);$before=$stateOf($job['id']);$sent=[];
        $service=new NotificationService($app,static function(string $body,string $key,string $token)use(&$sent):array{$sent[]=[$body,$key];return ['request_id'=>'offline-recovered','accepted_request_id'=>null,'status'=>200];});
        $run=static fn():array=>(new ReflectionMethod($service,'processBills'))->invoke($service,1);
        putenv('APP_URL=https://corrected-qr.example.test');
        try{
            $assert($run()['failed']===1&&$sent===[]);$assert((int)$stateOf($job['id'])['attempts']===0);
            $pdo->exec("UPDATE notification_outbox SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE id=".$job['id']);
            $assert($app->notifications()->enqueueBill($f['bill'])['newly_queued']);
            $assert($run()['sent']===1&&count($sent)===1);$after=$stateOf($job['id']);
            $assert($after['retry_key']!==$before['retry_key']&&str_contains($sent[0][0],'corrected-qr.example.test'));
            $assert($app->notifications()->enqueueBill($f['bill'])['enqueue_state']==='already_sent');
        }finally{putenv('APP_URL=https://line-qr.example.test');}
    });
});
$test('uncertain provider attempt never becomes unattempted or changes its retry body after URL drift',function()use($app,$pdo,$rollback,$quiet,$make,$assert,$stateOf):void{
    $rollback(function()use($app,$pdo,$quiet,$make,$assert,$stateOf):void{
        $quiet();$f=$make();$job=$app->notifications()->enqueueBill($f['bill']);$before=$stateOf($job['id']);$sent=[];
        $service=new NotificationService($app,static function(string $body,string $key,string $token)use(&$sent):array{$sent[]=[$body,$key];if(count($sent)===1)throw new LineDeliveryException('Offline uncertain timeout',true);return ['request_id'=>null,'accepted_request_id'=>'offline-replay','status'=>409];});
        $run=static fn():array=>(new ReflectionMethod($service,'processBills'))->invoke($service,1);
        $assert($run()['retried']===1);$pdo->exec('UPDATE notification_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id='.$job['id']);
        putenv('APP_URL=https://corrected-qr.example.test');
        try{
            $assert($run()['failed']===1&&count($sent)===1);$assert((int)$stateOf($job['id'])['attempts']===2);
            try{$app->notifications()->enqueueBill($f['bill']);throw new RuntimeException('Uncertain message regenerated');}
            catch(HttpException $e){$assert($e->errorCode==='LINE_DELIVERY_RECONCILIATION_REQUIRED');}
            $after=$stateOf($job['id']);$assert($after['payload']===$before['payload']&&$after['retry_key']===$before['retry_key']&&$after['status']==='failed');
        }finally{putenv('APP_URL=https://line-qr.example.test');}
        $app->notifications()->enqueueBill($f['bill']);$assert($run()['sent']===1&&$sent[0]===$sent[1]);
    });
});
$test('provider terminal rejection cannot reset attempted status or regenerate a request',function()use($app,$rollback,$quiet,$make,$assert,$stateOf):void{
    $rollback(function()use($app,$quiet,$make,$assert,$stateOf):void{
        $quiet();$f=$make();$job=$app->notifications()->enqueueBill($f['bill']);$before=$stateOf($job['id']);
        $service=new NotificationService($app,static function():never{throw new LineDeliveryException('Offline provider rejection',false,400);});
        $assert((new ReflectionMethod($service,'processBills'))->invoke($service,1)['failed']===1);
        $assert((int)$stateOf($job['id'])['attempts']===1);$app->notifications()->enqueueBill($f['bill']);$after=$stateOf($job['id']);
        $assert($after['retry_key']===$before['retry_key']&&$after['payload']===$before['payload']);
    });
});
$test('revoked delivery history does not override current delivery or worker failure health',function()use($app,$owner,$period,$rollback,$quiet,$make,$assert):void{
    $rollback(function()use($app,$owner,$period,$quiet,$make,$assert):void{
        $quiet();$f=$make();$app->notifications()->enqueueBill($f['bill']);
        $service=new NotificationService($app,static fn():array=>['request_id'=>'offline-current','accepted_request_id'=>null,'status'=>200]);
        $run=static fn():array=>(new ReflectionMethod($service,'processBills'))->invoke($service,1);
        $app->lineRoomBindings()->revokeAccount($f['resident'],$f['target']['id'],$owner);$assert($run()['failed']===1);
        $code=$app->lineRoomBindings()->issue($f['resident'],[],$owner);$app->lineRoomBindings()->consume($code['code'],$f['user'],0);
        $app->notifications()->enqueueBill($f['bill']);$assert($run()['sent']===1);
        $rows=array_values(array_filter($app->billing()->adminList($period),static fn(array $row):bool=>(int)$row['id']===$f['bill']));$row=$rows[0];
        $assert($row['line_status']==='sent'&&!$row['line_can_queue']&&$row['line_recipient_count']===1&&$row['line_previous_delivery_count']===1);
        $assert($row['line_delivery_counts']['failed']===0&&$row['line_delivery_counts']['sent']===1&&$row['line_last_error']===null);
        $assert($service->workerHealth()['queue']['failed']===0);
    });
});
$test('provider-attempt evidence survives a database retry followed by a QR validation failure',function()use($app,$quiet,$make,$assert,$stateOf):void{
    // No outer transaction: exercise Database's real deadlock retry loop.
    $quiet();$f=$make();$job=$app->notifications()->enqueueBill($f['bill']);$before=$stateOf($job['id']);$count=0;
    $service=new NotificationService($app,static function()use(&$count):never{
        $count++;putenv('APP_URL=https://corrected-qr.example.test');
        $error=new PDOException('Offline simulated deadlock after provider attempt');$error->errorInfo=['40001',1213,'offline'];throw $error;
    });
    try{
        $result=(new ReflectionMethod($service,'processBills'))->invoke($service,1);$after=$stateOf($job['id']);
        $assert($result['failed']===1&&$count===1&&(int)$after['attempts']===1);
        $assert($after['payload']===$before['payload']&&$after['retry_key']===$before['retry_key']);
    }finally{putenv('APP_URL=https://line-qr.example.test');}
});
fwrite(STDOUT,"{$passed} LINE payment QR groups passed; no provider calls or real transfers\n");
