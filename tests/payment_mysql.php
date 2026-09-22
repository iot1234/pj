<?php
declare(strict_types=1);

use Dormitory\Domain\PaymentService;
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;

// Disposable schema only; provider responses are injected in-process, never HTTPS.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^appj_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE')))exit(64);
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
$passed=0;
$assert=static function(bool $ok,string $why='Assertion failed'):void{if(!$ok)throw new RuntimeException($why);};
$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;echo "PASS {$name}\n";};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.' got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$reply=static fn(string $amount,string $ref):array=>['_status'=>200,'success'=>true,'data'=>[
    'success'=>true,'transRef'=>$ref,'amount'=>$amount,'transTimestamp'=>gmdate('Y-m-d\TH:i:s\Z'),
    'countryCode'=>'TH','paidLocalCurrency'=>'THB','receiver'=>['account'=>['value'=>'xxx567890']],
]];
$file=static fn(string $path):array=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>$path,'size'=>filesize($path)];
if(($argv[1]??'')==='upload'){
    $service=new PaymentService($app,static function($url,$headers,$body)use($reply,$argv):array{usleep(200000);return $reply($body['amount'],'PARALLEL-'.$argv[2]);});
    try{$result=$service->upload((int)$argv[2],(int)$argv[3],$file($argv[4]));}
    catch(HttpException $error){$result=['error_code'=>$error->errorCode];}
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
foreach(['admin_users','rooms','residents','bills','payments','transfer_instructions']as$table)$assert((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()===0,'Fresh fixture required');
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('payment_owner',?,'owner',1,1)");$q->execute([Password::hash('Payment-Fixture-Only-2026!')]);$owner=(int)$pdo->lastInsertId();
$app->billing()->updateSettings(['water_rate'=>'0','electric_rate'=>'0','due_days'=>7],$owner);
$app->settings()->update(['promptpay_target'=>'0812345678','promptpay_name'=>'Fixture receiver','line_basic_id'=>'@fixture','slip_provider'=>'slipok','slipok_branch_id'=>'fixture','slipok_api_key'=>'fixture-not-a-real-key','payment_receiver_account_tail'=>'567890'],$owner);
$period=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m');$n=0;$paths=[];
$make=function(bool $reserve=true)use($app,$owner,$period,&$n):array{
    $n++;$room=$app->rooms()->create(['room_code'=>'PAY-'.$n,'floor'=>1,'room_type'=>'Test','monthly_rent'=>'100.00']);
    $in=$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'phone'=>'0877'.str_pad((string)$n,6,'0',STR_PAD_LEFT),'full_name'=>'Payment fixture '.$n,'move_in_date'=>$period.'-01','opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'payment-fixture-resident-'.$n]);
    $app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner);
    $input=['period'=>$period,'room_ids'=>[$room['id']],'confirm_current_period'=>true];$preview=$app->billing()->preview($input);
    $bill=$app->billing()->bulk($input+['preview_token'=>$preview['preview_token']],$owner)['created'][0];
    $b=['id'=>(int)$bill['id'],'resident_id'=>(int)$in['resident_id'],'amount'=>'100.00'];
    if($reserve)$b['amount']=$app->transfers()->reserve($b['id'],$b['resident_id'])['transfer_amount'];
    return $b;
};
$imageSequence=0;
$image=function()use(&$paths,$app,$file,&$imageSequence):array{
    $path=tempnam($app->config->root.'/storage','payment-fixture-');$paths[]=$path;
    $imageSequence++;$im=imagecreatetruecolor(16,16);imagefill($im,0,0,imagecolorallocate($im,($imageSequence>>16)&255,($imageSequence>>8)&255,$imageSequence&255));imagepng($im,$path);unset($im);
    return $file($path);
};
$mode='verified';$calls=0;$onRequest=null;$reference=null;
$service=new PaymentService($app,static function($url,$headers,$body)use(&$mode,&$calls,&$onRequest,&$reference,$reply):array{
    $calls++;if($onRequest!==null)$onRequest();
    if($mode==='timeout')throw new RuntimeException('Simulated connection loss');
    if($mode==='malformed')return ['_status'=>200,'success'=>true];
    if($mode==='duplicate')return ['_status'=>400,'code'=>1012];
    $result=$reply($mode==='wrong_amount'?'1.00':$body['amount'],$reference??'PAYMENT-FIXTURE-'.$calls);
    if($mode==='future')$result['data']['transTimestamp']=gmdate('Y-m-d\TH:i:s\Z',time()+86400);
    return $result;
});
$upload=static fn(array $b,array $f,?callable $after=null):array=>$service->upload($b['id'],$b['resident_id'],$f,$after);
$status=static function(array $b)use($pdo):string{$q=$pdo->prepare('SELECT status FROM bills WHERE id=?');$q->execute([$b['id']]);return (string)$q->fetchColumn();};

try{
    $test('reserved cents verify atomically without changing bill principal',function()use($make,$image,$upload,$app,$pdo,$status,$assert):void{
        $b=$make();$p=$upload($b,$image(),function($p)use($pdo,$assert):void{$assert($pdo->inTransaction()&&$p['status']==='verified','Audit must participate in commit');});
        $assert($p['status']==='verified'&&$p['amount']==='100.00'&&$status($b)==='paid');
        $assert($app->transfers()->find($b['id'])['status']==='settled');
        $payload=json_decode($pdo->query('SELECT provider_payload FROM payments WHERE id='.$p['id'])->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $assert($payload['expected_transfer_amount']===$b['amount']&&$payload['bill_amount']==='100.00');
    });
    $test('lost verified response replays the same evidence without another provider charge',function()use($make,$image,$upload,&$calls,$assert):void{
        $b=$make();$f=$image();$p=$upload($b,$f);$before=$calls;$again=$upload($b,$f);
        $assert($again['id']===$p['id']&&$again['status']==='verified'&&$again['idempotent_replay']===true&&$calls===$before);
    });
    $test('legacy bill without QR reservation still requires verified principal',function()use($make,$image,$upload,$status,$assert):void{$b=$make(false);$p=$upload($b,$image());$assert($p['status']==='verified'&&$status($b)==='paid');});
    $test('wrong resident and invalid files cannot create payments',function()use($make,$image,$service,$upload,$expect,$pdo,$assert):void{
        $b=$make();$f=$image();$before=(int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
        $expect(fn()=>$service->upload($b['id'],$b['resident_id']+999,$f),'BILL_NOT_FOUND');
        $expect(fn()=>$upload($b,[]),'SLIP_UPLOAD_ERROR');
        $expect(fn()=>$upload($b,array_replace($f,['size'=>5000000])),'SLIP_TOO_LARGE');
        $expect(fn()=>$upload($b,array_replace($f,['error'=>UPLOAD_ERR_PARTIAL])),'SLIP_UPLOAD_ERROR');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn()===$before);
    });
    $test('timeout persists one pending slip; duplicate submission replays; retry settles it',function()use($make,$image,$upload,$service,$pdo,$status,$assert,$expect,&$mode,&$calls):void{
        $b=$make();$f=$image();$mode='timeout';$p=$upload($b,$f);$before=$calls;$again=$upload($b,$f);
        $assert($p['status']==='pending'&&$status($b)==='pending'&&$again['id']===$p['id']&&$calls===$before);
        $assert($pdo->query('SELECT verification_token FROM payments WHERE id='.$p['id'])->fetchColumn()===null);
        $expect(fn()=>$upload($b,$image()),'PAYMENT_ALREADY_PENDING');
        $mode='verified';$assert($service->retry($p['id'])['status']==='verified'&&$status($b)==='paid');
    });
    $test('provider duplicate or malformed response never marks a bill paid',function()use($make,$image,$upload,$status,$assert,&$mode):void{
        foreach(['duplicate','malformed']as$case){$mode=$case;$b=$make();$assert($upload($b,$image())['status']==='pending'&&$status($b)==='pending');}$mode='verified';
    });
    $test('wrong amounts and future transfers cannot pay a bill',function()use($make,$image,$upload,$status,$assert,&$mode):void{
        foreach(['wrong_amount','future']as$case){$mode=$case;$b=$make();$assert($upload($b,$image())['status']==='rejected'&&$status($b)==='pending');}$mode='verified';
    });
    $test('same evidence and bank reference cannot pay a second bill',function()use($make,$image,$upload,$status,$assert,$expect,&$reference):void{
        $a=$make();$b=$make();$f=$image();$reference='SAME-BANK-REFERENCE';$upload($a,$f);
        $expect(fn()=>$upload($b,$f),'DUPLICATE_SLIP');$assert($upload($b,$image())['status']==='rejected'&&$status($b)==='pending');$reference=null;
    });
    $test('changed QR receiver blocks QR but retains uploaded evidence with a reviewable result',function()use($make,$image,$upload,$service,$app,$pdo,$owner,$status,$assert,&$calls):void{
        $b=$make();$pdo->exec("UPDATE integration_settings SET promptpay_target='0812345679' WHERE id=1");$before=$calls;
        $detail=$app->billing()->residentDetail($b['resident_id'],$b['id']);$assert($detail['payment_capabilities']['transfer_instruction_ready']===false);
        $p=$upload($b,$image());$assert($p['status']==='pending'&&$calls===$before&&str_contains($p['rejection_reason'],'เก็บสลิปไว้แล้ว')&&$status($b)==='pending');
        $app->settings()->update(['promptpay_target'=>'0812345678'],$owner);$assert($service->retry($p['id'])['status']==='verified');
    });
    $test('settings changed during verification keep the result pending',function()use($make,$image,$upload,$app,$owner,$status,$assert,&$onRequest):void{
        $b=$make();$onRequest=fn()=>$app->settings()->update(['payment_receiver_account_tail'=>'678901'],$owner);
        $assert($upload($b,$image())['status']==='pending'&&$status($b)==='pending');$onRequest=null;
        $app->settings()->update(['payment_receiver_account_tail'=>'567890'],$owner);
    });
    $test('strict audit failure rolls back settlement and leaves evidence recoverable',function()use($make,$image,$upload,$service,$app,$status,$assert,&$mode):void{
        $b=$make();$f=$image();$failed=false;try{$upload($b,$f,static function(){throw new RuntimeException('Simulated audit failure');});}catch(RuntimeException $e){$failed=$e->getMessage()==='Simulated audit failure';}
        $assert($failed&&$status($b)==='pending'&&$app->transfers()->find($b['id'])['status']==='reserved');
        $p=$upload($b,$f);$assert($p['status']==='pending'&&$p['idempotent_replay']);
        $mode='duplicate';$assert($service->retry($p['id'])['status']==='pending');$mode='verified';
    });
    $test('lease fencing ignores a late verifier and blocks close/retry during a live claim',function()use($make,$image,$upload,$service,$app,$pdo,$status,$assert,$expect,&$mode):void{
        $b=$make();$mode='timeout';$p=$upload($b,$image());$mode='verified';
        $claim=new ReflectionMethod(PaymentService::class,'claimRetry');$finish=new ReflectionMethod(PaymentService::class,'finalizeReserved');
        $first=bin2hex(random_bytes(32));$second=bin2hex(random_bytes(32));$claim->invoke($service,$p['id'],$first);
        $expect(fn()=>$service->retry($p['id']),'PAYMENT_VERIFICATION_IN_PROGRESS');$expect(fn()=>$service->closePending($p['id'],['reason'=>'Fixture close reason']),'PAYMENT_VERIFICATION_IN_PROGRESS');
        $pdo->exec('UPDATE payments SET verification_lease_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id='.$p['id']);$claim->invoke($service,$p['id'],$second);
        $v=['decision'=>'verified','provider'=>'slipok','transaction_ref'=>'FENCED-REF','receiver_ref'=>null,'reason'=>null,'payload'=>['amount'=>$b['amount']],'settings_fingerprint'=>$app->settings()->slipVerificationSettings()['fingerprint']];
        $assert($finish->invoke($service,$p['id'],$b['id'],$first,$v)['status']==='pending'&&$status($b)==='pending');
        $assert($finish->invoke($service,$p['id'],$b['id'],$second,$v)['status']==='verified'&&$status($b)==='paid');
    });
    $test('legacy finalization also rejects missing verification fingerprint and wrong amount',function()use($make,$image,$upload,$service,$app,$assert,$status,&$mode):void{
        foreach(['missing_fingerprint','wrong_amount']as$case){
            $b=$make(false);$mode='timeout';$p=$upload($b,$image());$mode='verified';$token=bin2hex(random_bytes(32));
            (new ReflectionMethod(PaymentService::class,'claimRetry'))->invoke($service,$p['id'],$token);
            $v=['decision'=>'verified','provider'=>'slipok','transaction_ref'=>'INCOMPLETE-'.$p['id'],'payload'=>['amount'=>'100.00']];
            if($case==='wrong_amount'){$v['payload']['amount']='1.00';$v['settings_fingerprint']=$app->settings()->slipVerificationSettings()['fingerprint'];}
            $result=(new ReflectionMethod(PaymentService::class,'finalizeReserved'))->invoke($service,$p['id'],$b['id'],$token,$v);
            $assert($result['status']==='pending'&&$status($b)==='pending');
        }
    });
    $test('closing a pending slip is final, preserves evidence, and never pays the bill',function()use($make,$image,$upload,$service,$assert,$expect,$status,&$mode):void{
        $b=$make();$f=$image();$mode='timeout';$p=$upload($b,$f);$mode='verified';
        $expect(fn()=>$service->closePending($p['id'],['reason'=>'x']),'VALIDATION_ERROR');
        $closed=$service->closePending($p['id'],['reason'=>'ตรวจสอบรายการกับผู้พักแล้ว']);$assert($closed['status']==='rejected'&&$status($b)==='pending');
        $assert($upload($b,$f)['status']==='rejected'&&$service->evidence($p['id'])['mime']==='image/png');
        $expect(fn()=>$service->retry($p['id']),'PAYMENT_NOT_PENDING');
    });
    $test('tampered stored evidence is never sent to a provider',function()use($make,$image,$upload,$service,$app,$pdo,$expect,$assert,&$mode,&$calls):void{
        $b=$make();$mode='timeout';$p=$upload($b,$image());$mode='verified';
        $relative=$pdo->query('SELECT slip_path FROM payments WHERE id='.$p['id'])->fetchColumn();
        $im=imagecreatetruecolor(2,2);imagepng($im,$app->config->root.'/'.$relative);unset($im);$before=$calls;
        $expect(fn()=>$service->retry($p['id']),'SLIP_FILE_INTEGRITY_FAILED');$assert($calls===$before);
    });
    $test('parallel identical uploads create exactly one payment',function()use($make,$image,$pdo,$assert):void{
        $b=$make();$f=$image();$processes=[];
        for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,__FILE__,'upload',(string)$b['id'],(string)$b['resident_id'],$f['tmp_name']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$processes[]=[$p,$pipes];}
        $results=[];foreach($processes as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($p)===0,$err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
        $assert(isset($results[0]['id'],$results[1]['id'])&&$results[0]['id']===$results[1]['id']);$assert((int)$pdo->query('SELECT COUNT(*) FROM payments WHERE bill_id='.$b['id'])->fetchColumn()===1);
    });
    $test('parallel different slips for one bill cannot both reach verification',function()use($make,$image,$pdo,$assert):void{
        $b=$make();$processes=[];
        foreach([$image(),$image()]as$f){$p=proc_open([PHP_BINARY,__FILE__,'upload',(string)$b['id'],(string)$b['resident_id'],$f['tmp_name']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$processes[]=[$p,$pipes];}
        $results=[];foreach($processes as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($p)===0,$err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
        $assert(count(array_filter($results,static fn($r)=>isset($r['id'])))===1);
        $errors=array_values(array_filter($results,static fn($r)=>isset($r['error_code'])));$assert(count($errors)===1&&in_array($errors[0]['error_code'],['PAYMENT_ALREADY_PENDING','BILL_ALREADY_PAID'],true));
        $assert((int)$pdo->query('SELECT COUNT(*) FROM payments WHERE bill_id='.$b['id'])->fetchColumn()===1);
    });
    $test('disabling the slip provider rejects new uploads without changing the bill',function()use($make,$image,$upload,$app,$owner,$pdo,$status,$assert,$expect,&$calls):void{
        $b=$make();$before=$calls;$app->settings()->update(['slip_provider'=>'none'],$owner);
        $expect(fn()=>$upload($b,$image()),'SLIP_NOT_CONFIGURED');
        $assert($calls===$before&&$status($b)==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM payments WHERE bill_id='.$b['id'])->fetchColumn()===0);
        $app->settings()->update(['slip_provider'=>'slipok'],$owner);
    });
    $test('payment list exposes principal and reserved transfer separately',function()use($service,$assert):void{
        $list=$service->list('',0,200);$assert($list['pending_count']>0&&$list['has_more']===false);
        foreach($list['items']as$p)$assert($p['amount']==='100.00'&&isset($p['transfer_amount'],$p['transfer_adjustment']));
    });
}finally{foreach($paths as$path)if(is_file($path))unlink($path);}
echo "{$passed} payment integration groups passed; no external provider calls\n";
