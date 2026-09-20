<?php
declare(strict_types=1);
use Dormitory\Http\HttpException;
use Dormitory\Security\Password;
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||preg_match('/^appj_[a-z0-9_]+$/D',(string)getenv('DB_DATABASE'))!==1){fwrite(STDERR,"Dedicated empty testing database required\n");exit(64);}
$app=require dirname(__DIR__).'/bootstrap.php';$pdo=$app->database()->pdo();
foreach(['admin_users','rooms','bills','residents']as$table)if((int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()!==0)throw new RuntimeException('Fresh fixture required');
$assert=static function(bool $v,string $why='Assertion failed'):void{if(!$v)throw new RuntimeException($why);};
$expect=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(HttpException $e){$assert($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}throw new RuntimeException('Expected '.$code);};
$passed=0;$test=static function(string $name,callable $fn)use(&$passed):void{$fn();$passed++;fwrite(STDOUT,"PASS {$name}\n");};
$q=$pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active)VALUES('defaults_owner',?,'owner',1,1)");$q->execute([Password::hash('Fixture-Only-Billing-2026!')]);$owner=(int)$pdo->lastInsertId();
$today=new DateTimeImmutable('today',new DateTimeZone((string)$app->config->get('APP_TIMEZONE','Asia/Bangkok')));$period=$today->format('Y-m');
$room=$app->rooms()->create(['room_code'=>'AUTO-101','floor'=>1,'room_type'=>'Test','monthly_rent'=>'3000.00']);
$app->bookings()->createAdminResident($owner,['room_id'=>$room['id'],'full_name'=>'Defaults Fixture','phone'=>'0817990001','move_in_date'=>$today->format('Y-m-d'),'opening_water_reading'=>'100.00','opening_electric_reading'=>'200.00','idempotency_key'=>'billing-defaults-fixture-01']);
$app->meters()->record(['room_id'=>$room['id'],'period'=>$period,'water_current'=>'110.00','electric_current'=>'220.00'],$owner);
$input=['period'=>$period,'room_ids'=>[$room['id']],'confirm_current_period'=>true];
$test('automatic defaults do not bypass unconfirmed billing settings',fn()=>$expect(fn()=>$app->billing()->preview($input),'BILLING_SETTINGS_NOT_CONFIRMED'));
$app->billing()->updateSettings(['water_rate'=>'18.50','electric_rate'=>'7.25','due_days'=>7],$owner);
$preview=$app->billing()->preview($input);
$test('server derives exact rates, totals and due date without duplicated form data',function()use($preview,$today,$assert):void{
 $assert($preview['due_date']===$today->modify('+7 days')->format('Y-m-d'));
 $bill=$preview['bills'][0];$assert($bill['water_rate']==='18.50'&&$bill['electric_rate']==='7.25'&&$bill['total_amount']==='3330.00');
});
$test('explicit invalid dates and unfinalized current month remain rejected',function()use($app,$input,$expect):void{
 $expect(fn()=>$app->billing()->preview($input+['due_date'=>null]),'VALIDATION_ERROR');
 $expect(fn()=>$app->billing()->preview(array_replace($input,['confirm_current_period'=>false])),'CURRENT_BILLING_PERIOD_NOT_FINALIZED');
});
$test('changing central rates after preview cannot create a silently different bill',function()use($app,$owner,$input,$preview,$expect,$pdo,$assert):void{
 $app->billing()->updateSettings(['water_rate'=>'19.00','electric_rate'=>'7.25','due_days'=>7],$owner);
 $expect(fn()=>$app->billing()->bulk($input+['preview_token'=>$preview['preview_token']],$owner),'BILL_PREVIEW_CHANGED');
 $assert((int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn()===0);
});
$test('changing central due days invalidates the old preview as well',function()use($app,$owner,$input,$expect):void{
 $old=$app->billing()->preview($input);$app->billing()->updateSettings(['water_rate'=>'19.00','electric_rate'=>'7.25','due_days'=>10],$owner);
 $expect(fn()=>$app->billing()->bulk($input+['preview_token'=>$old['preview_token']],$owner),'BILL_PREVIEW_CHANGED');
});
$test('locked billing defaults exclude a concurrent settings writer',function()use($app,$assert):void{
 $peer=(new Dormitory\Database($app->config))->pdo();$peer->exec('SET SESSION innodb_lock_wait_timeout=1');
 $app->database()->transaction(function()use($app,$peer,$assert):void{
  $app->billing()->settings(true);$blocked=false;
  try{$peer->exec('UPDATE billing_settings SET due_days=11 WHERE id=1');}catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}
  $assert($blocked,'Concurrent defaults update bypassed the row lock');
 });
});
$test('fresh preview issues the exact recalculated snapshot once',function()use($app,$owner,$input,$pdo,$today,$assert):void{
 $fresh=$app->billing()->preview($input);$result=$app->billing()->bulk($input+['preview_token'=>$fresh['preview_token']],$owner);
 $assert(count($result['created'])===1);$row=$pdo->query('SELECT due_date,water_rate,electric_rate,total_amount FROM bills')->fetch();
 $assert($row['due_date']===$today->modify('+10 days')->format('Y-m-d')&&$row['water_rate']==='19.00'&&$row['total_amount']==='3335.00');
 try{$app->billing()->bulk($input+['preview_token'=>$fresh['preview_token']],$owner);}catch(HttpException $e){$assert(in_array($e->errorCode,['BILL_PREVIEW_CHANGED','BILL_PREVIEW_INVALID'],true));}
 $assert((int)$pdo->query('SELECT COUNT(*) FROM bills')->fetchColumn()===1,'Retry duplicated the issued bill');
});
fwrite(STDOUT,"{$passed} automatic billing MySQL tests passed\n");
