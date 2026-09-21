<?php
declare(strict_types=1);
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
use Dormitory\Integration\SlipVerifier;
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^appj_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE')))exit(64);
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
if(($argv[1]??'')==='reserve'){fwrite(STDOUT,json_encode($app->transfers()->reserve((int)$argv[2],(int)$argv[3]),JSON_THROW_ON_ERROR));exit;}
foreach(['admin_users','rooms','residents','bills','transfer_instructions']as$t)if((int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn()!==0)throw new RuntimeException('Fresh fixture required');
$passed=0;$assert=static function(bool $ok,string $why='Assertion failed'):void{if(!$ok)throw new RuntimeException($why);};
$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.' got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('transfer_owner',?,'owner',1,1)");$q->execute([Password::hash('Fixture-Transfer-Only-2026!')]);$owner=(int)$pdo->lastInsertId();
$app->billing()->updateSettings(['water_rate'=>'0','electric_rate'=>'0','due_days'=>7],$owner);
$app->settings()->update(['promptpay_target'=>'0812345678','promptpay_name'=>'Fixture receiver','line_basic_id'=>'@fixture','slip_provider'=>'none'],$owner);
$period=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m');$n=0;
$make=function(string $rent='100.00')use($app,$owner,$period,&$n):array{
 $n++;$r=$app->rooms()->create(['room_code'=>'TRANSFER-'.$n,'floor'=>1,'room_type'=>'Fixture','monthly_rent'=>$rent]);
 $in=$app->bookings()->createAdminResident($owner,['room_id'=>$r['id'],'phone'=>'0876'.str_pad((string)$n,6,'0',STR_PAD_LEFT),'full_name'=>'Fixture '.$n,'move_in_date'=>$period.'-01','opening_water_reading'=>'0','opening_electric_reading'=>'0','idempotency_key'=>'unique-transfer-fixture-'.$n]);
 $app->meters()->record(['room_id'=>$r['id'],'period'=>$period,'water_current'=>'0','electric_current'=>'0'],$owner);
 $input=['period'=>$period,'room_ids'=>[$r['id']],'confirm_current_period'=>true];$p=$app->billing()->preview($input);$b=$app->billing()->bulk($input+['preview_token'=>$p['preview_token']],$owner)['created'][0];
 return ['id'=>(int)$b['id'],'resident_id'=>(int)$in['resident_id']];
};
$one=$make();$instruction=$app->transfers()->reserve($one['id'],$one['resident_id']);
$test('QR allocates while no slip provider is configured',function()use($instruction,$app,$assert):void{$assert(!$app->settings()->publicSettings()['slip_verification_ready']);$assert($instruction['bill_amount']==='100.00'&&(float)$instruction['transfer_amount']>100&&(float)$instruction['transfer_amount']<101);});
$test('refresh and a new application instance reuse the exact stored amount',function()use($app,$one,$instruction,$assert):void{$other=new Dormitory\Application($app->config);$assert($other->transfers()->reserve($one['id'],$one['resident_id'])===$instruction);});
$test('enabling or disabling verification never changes QR readiness or locked amount',function()use($app,$owner,$one,$instruction,$assert):void{
 foreach(['slipok','easyslip','none']as$provider){
  $app->settings()->update(['slip_provider'=>$provider,'payment_receiver_account_tail'=>'567890','slipok_branch_id'=>'fixture','slipok_api_key'=>'fixture-slipok-key','easyslip_api_key'=>'fixture-easyslip-key'],$owner);
  $bill=$app->billing()->residentDetail($one['resident_id'],$one['id']);$app->billing()->assertPromptPayAvailable($bill);
  $assert($bill['payment_capabilities']['promptpay_ready']&&$bill['payment_capabilities']['slip_verification_ready']===($provider!=='none'));
  $assert($app->transfers()->reserve($one['id'],$one['resident_id'])['transfer_amount']===$instruction['transfer_amount']);
 }
});
$test('invalid selected or unused slip credentials disable upload but cannot break QR or LINE fallback',function()use($app,$owner,$pdo,$one,$instruction,$assert,$expect):void{
 $app->settings()->update(['slip_provider'=>'slipok','slipok_branch_id'=>'fixture','payment_receiver_account_tail'=>'567890'],$owner);
 $pdo->exec("UPDATE integration_settings SET slipok_api_key_enc='v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' WHERE id=1");
 try{
  foreach(['slipok','none']as$provider){
   $pdo->prepare('UPDATE integration_settings SET slip_provider=? WHERE id=1')->execute([$provider]);
   $bill=$app->billing()->residentDetail($one['resident_id'],$one['id']);$app->billing()->assertPromptPayAvailable($bill);
   $assert($bill['payment_capabilities']['slip_verification_ready']===false&&$bill['payment_capabilities']['promptpay_ready']===true);
   $reserved=$app->transfers()->reserve($one['id'],$one['resident_id']);$envelope=$app->transfers()->envelope($reserved,$bill);
   $assert($envelope['amount']===$instruction['transfer_amount']&&$envelope['line_fallback']['available']);
   $expect(fn()=>$app->payments()->upload($one['id'],$one['resident_id'],[]),'SLIP_NOT_CONFIGURED');
  }
 }finally{$pdo->exec("UPDATE integration_settings SET slipok_api_key_enc=NULL,slip_provider='none' WHERE id=1");}
});
$test('missing LINE contact does not disable QR or invent another recipient',function()use($app,$owner,$one,$instruction,$assert):void{
 $app->settings()->update(['line_basic_id'=>null],$owner);
 try{$bill=$app->billing()->residentDetail($one['resident_id'],$one['id']);$e=$app->transfers()->envelope($instruction,$bill);$assert($e['line_fallback']['available']===false&&$e['line_fallback']['url']===null&&$e['amount']===$instruction['transfer_amount']);}
 finally{$app->settings()->update(['line_basic_id'=>'@fixture'],$owner);}
});
$test('another resident cannot allocate or read this bill',fn()=>$expect(fn()=>$app->transfers()->reserve($one['id'],$one['resident_id']+1000),'BILL_NOT_FOUND'));
$test('LINE fallback is scoped to the bill and only opens the official chat',function()use($app,$one,$instruction,$assert):void{
 $bill=$app->billing()->residentDetail($one['resident_id'],$one['id']);$e=$app->transfers()->envelope($instruction,$bill);
 $assert($e['payload']===Dormitory\Domain\PromptPayService::payload('0812345678',$instruction['transfer_amount']));
 $assert(str_starts_with($e['line_fallback']['url'],'https://line.me/R/oaMessage/%40fixture/?')&&str_contains($e['line_fallback']['message'],$bill['bill_no']));
 $assert($bill['total_amount']==='100.00'&&$bill['status']==='pending');
});
$test('changed receiver never rerolls a shown QR or silently substitutes another account',function()use($app,$owner,$one,$instruction,$expect,$assert):void{
 $app->settings()->update(['promptpay_target'=>'0812345679'],$owner);$expect(fn()=>$app->transfers()->reserve($one['id'],$one['resident_id']),'TRANSFER_TARGET_CHANGED');
 $assert($app->transfers()->find($one['id'])===$instruction);$app->settings()->update(['promptpay_target'=>'0812345678'],$owner);
});
$test('transfer snapshots and unpaid reservations cannot be altered or released',function()use($pdo,$one,$assert):void{
 foreach(["UPDATE transfer_instructions SET adjustment_amount=IF(adjustment_amount=0.99,0.98,0.99),transfer_amount=bill_amount+adjustment_amount WHERE bill_id=", "UPDATE transfer_instructions SET status='released',settled_at=UTC_TIMESTAMP(6) WHERE bill_id="]as$sql){$blocked=false;try{$pdo->exec($sql.$one['id']);}catch(PDOException){$blocked=true;}$assert($blocked);}
});
$test('same-base reservations exhaust 99 slots without collision or overflow',function()use($make,$app,$instruction,$assert,$expect):void{
 $amounts=[$instruction['transfer_amount']];for($i=1;$i<99;$i++){$b=$make();$amounts[]=$app->transfers()->reserve($b['id'],$b['resident_id'])['transfer_amount'];}
 $assert(count(array_unique($amounts))===99);$b=$make();$expect(fn()=>$app->transfers()->reserve($b['id'],$b['resident_id']),'TRANSFER_SLOTS_FULL');
});
$test('fractional principals also get +0.01 to +0.99 without losing their original cents',function()use($make,$app,$assert):void{$b=$make('500.75');$r=$app->transfers()->reserve($b['id'],$b['resident_id']);$assert($r['bill_amount']==='500.75'&&Dormitory\Support\Validator::scaledDecimal($r['transfer_amount'],'amount',2,12)-50075===Dormitory\Support\Validator::scaledDecimal($r['adjustment_amount'],'adjustment',2,12));});
$test('overlapping fractional bill ranges share one global amount registry',function()use($make,$app,$assert):void{
 $amounts=[];foreach(['600.00','600.01','600.25','600.50','600.75','600.99']as$base){$b=$make($base);$amounts[]=$app->transfers()->reserve($b['id'],$b['resident_id'])['transfer_amount'];}
 $assert(count(array_unique($amounts))===count($amounts));
});
$test('database unique index blocks colliding transfer instructions even outside allocator',function()use($make,$app,$pdo,$assert):void{
 $a=$make('800.00');$b=$make('800.00');$r=$app->transfers()->reserve($a['id'],$a['resident_id']);$blocked=false;
 try{$q=$pdo->prepare('INSERT INTO transfer_instructions(bill_id,resident_id,bill_amount,adjustment_amount,transfer_amount,promptpay_target) VALUES(?,?,?,?,?,?)');$q->execute([$b['id'],$b['resident_id'],$r['bill_amount'],$r['adjustment_amount'],$r['transfer_amount'],$r['promptpay_target']]);}
 catch(PDOException $e){$blocked=Dormitory\Support\MySqlError::isDuplicateKey($e,'uq_transfer_active_amount');}
 $assert($blocked,'Unique transfer amount guard did not reject collision');
});
$test('parallel processes allocate once for the same bill and unique amounts for other bills',function()use($make,$app,$assert):void{
 $a=$make('700.00');$b=$make('700.00');$c=$make('700.00');$processes=[];
 foreach([$a,$a,$b,$c]as$item){$p=proc_open([PHP_BINARY,__FILE__,'reserve',(string)$item['id'],(string)$item['resident_id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);$processes[]=[$p,$pipes];}
 $results=[];foreach($processes as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($p)===0,$err);$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
 $assert($results[0]['transfer_amount']===$results[1]['transfer_amount']);$assert(count(array_unique(array_column($results,'transfer_amount')))===3);
});

$test('slip verification expects the reserved transfer amount rather than changing invoice principal',function()use($app,$one,$instruction,$assert):void{$assert($app->transfers()->expectedAmount($one['id'],'100.00')===$instruction['transfer_amount']);});
fwrite(STDOUT,"{$passed} transfer reservation groups passed; no external calls\n");
