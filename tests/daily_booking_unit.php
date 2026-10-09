<?php
declare(strict_types=1);

use Dormitory\Domain\DailyBookingService;
use Dormitory\Http\HttpException;

if(PHP_SAPI!=='cli')exit(64);
putenv('APP_ENV=testing');putenv('APP_KEY=daily-booking-unit-fixture-key-2026-32bytes');
putenv('APP_URL=http://127.0.0.1');putenv('APP_TIMEZONE=Asia/Bangkok');putenv('FORCE_HTTPS=false');
$app=require dirname(__DIR__).'/bootstrap.php';
$service=new DailyBookingService($app);$checks=0;
$assert=static function(bool $condition,string $why)use(&$checks):void{if(!$condition)throw new RuntimeException($why);$checks++;};
$assert(DailyBookingService::nightDates('2026-10-10','2026-10-12')===['2026-10-10','2026-10-11'],'Checkout must be exclusive');
$assert(DailyBookingService::nightDates('2028-02-28','2028-03-01')===['2028-02-28','2028-02-29'],'Leap day must count as one night');
$assert(DailyBookingService::nightDates('2026-12-31','2027-01-02')===['2026-12-31','2027-01-01'],'Year boundary must preserve dates');
$dates=new ReflectionMethod(DailyBookingService::class,'dates');
$today=(new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$tomorrow=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$assert($dates->invoke($service,['check_in_date'=>$today,'check_out_date'=>$tomorrow,'guests'=>2])===[$today,$tomorrow,2,1],'Date validation must count valid nights');
foreach([
    ['check_in_date'=>$today,'check_out_date'=>$today,'guests'=>1],
    ['check_in_date'=>$today,'check_out_date'=>$tomorrow,'guests'=>21],
    ['check_in_date'=>$today,'check_out_date'=>$tomorrow,'guests'=>[]],
    ['check_in_date'=>$today,'check_out_date'=>(new DateTimeImmutable($today))->modify('+91 days')->format('Y-m-d'),'guests'=>1],
    ['check_in_date'=>(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d'),'check_out_date'=>$tomorrow,'guests'=>1],
]as$invalid){try{$dates->invoke($service,$invalid);throw new RuntimeException('Invalid dates or capacity accepted');}catch(HttpException $e){$assert($e->status===422,'Invalid request must fail before database access');}}
$quote=new ReflectionMethod(DailyBookingService::class,'roomQuote');$verify=new ReflectionMethod(DailyBookingService::class,'assertQuote');
$room=['id'=>7,'room_code'=>'D7','floor'=>1,'room_type'=>'daily','description'=>null,'amenities'=>'[]','image_key'=>null,'rental_mode'=>'daily','housekeeping_status'=>'ready','housekeeping_version'=>1,'max_guests'=>2,'daily_rate'=>'650.25','daily_deposit'=>'100.00','updated_at'=>'2026-10-01 00:00:00.000000'];
$end=(new DateTimeImmutable($today))->modify('+3 days')->format('Y-m-d');
$price=$quote->invoke($service,$room,$today,$end,2,3,true);
$assert($price['room_amount']==='1950.75'&&$price['total_amount']==='2050.75','Prices must use exact integer satang');
$verify->invoke($service,$price['quote_token'],$room,$today,$end,2,$price);$checks++;
try{$verify->invoke($service,substr($price['quote_token'],0,-1).'x',$room,$today,$end,2,$price);throw new RuntimeException('Tampered quote accepted');}catch(HttpException $e){$assert($e->errorCode==='DAILY_QUOTE_INVALID','Forged quote must fail');}
$changed=$room;$changed['daily_rate']='700.00';$newPrice=$quote->invoke($service,$changed,$today,$end,2,3,false);
try{$verify->invoke($service,$price['quote_token'],$changed,$today,$end,2,$newPrice);throw new RuntimeException('Stale price quote accepted');}catch(HttpException $e){$assert($e->errorCode==='DAILY_QUOTE_CHANGED','Price changes must invalidate old quote');}
try{$verify->invoke($service,$price['quote_token'],$room,$today,$end,1,$price);throw new RuntimeException('Changed capacity quote accepted');}catch(HttpException $e){$assert($e->errorCode==='DAILY_QUOTE_CHANGED','Guest changes must invalidate old quote');}
$expired=json_decode(base64_decode(strtr(explode('.',$price['quote_token'])[0],'-_','+/')),true,512,JSON_THROW_ON_ERROR);$expired['expires']=time()-1;
$body=rtrim(strtr(base64_encode(json_encode($expired,JSON_THROW_ON_ERROR)),'+/','-_'),'=');$expiredToken=$body.'.'.hash_hmac('sha256',"daily-quote:v1\0".$body,$app->config->appKey());
try{$verify->invoke($service,$expiredToken,$room,$today,$end,2,$price);throw new RuntimeException('Expired signed quote accepted');}catch(HttpException $e){$assert($e->errorCode==='DAILY_QUOTE_EXPIRED','A valid signature must not extend quote lifetime');}
$midnight=(new DateTimeImmutable('2026-10-10 00:00:00',new DateTimeZone('Asia/Bangkok')))->getTimestamp();
$nearEnd=$quote->invoke($service,$room,'2026-10-09','2026-10-10',1,1,true,$midnight-60);
$assert($nearEnd['quote_expires_at']==='2026-10-09T17:00:00Z','A quote cannot outlive the local checkout boundary');
$verify->invoke($service,$nearEnd['quote_token'],$room,'2026-10-09','2026-10-10',1,$nearEnd,$midnight-1);$checks++;
try{$verify->invoke($service,$nearEnd['quote_token'],$room,'2026-10-09','2026-10-10',1,$nearEnd,$midnight);throw new RuntimeException('Ended stay quote accepted');}catch(HttpException $e){$assert($e->errorCode==='DAILY_QUOTE_EXPIRED','Checkout boundary uses the supplied authoritative clock');}
foreach([['from'=>'2026-10-10','to'=>'2026-10-09'],['limit'=>5001]]as$filter){try{$service->all($filter);throw new RuntimeException('Invalid owner list filter accepted');}catch(HttpException $e){$assert($e->errorCode==='VALIDATION_ERROR','Invalid list requests fail before database access');}}
$token=new ReflectionMethod(DailyBookingService::class,'accessToken');$identity=['reference_no'=>'DY-TEST-0001','idempotency_key'=>'daily-unit-key-0001'];
$first=$token->invoke($service,$identity);$assert($first===$token->invoke($service,$identity),'A replay must recover the same guest token');
$assert($first!==$token->invoke($service,['reference_no'=>'DY-TEST-0002','idempotency_key'=>'daily-unit-key-0001']),'A token must bind the booking identity');
$checksNormalizer=new ReflectionMethod(\Dormitory\Support\DailyBookingSchema::class,'normalizedCheck');
$a=$checksNormalizer->invoke(null,'((room_amount = (nightly_rate * (to_days(check_out_date) - to_days(check_in_date)))))');
$b=$checksNormalizer->invoke(null,'((room_amount = ((nightly_rate * to_days(check_out_date)) - to_days(check_in_date))))');
$assert($a!==$b,'CHECK normalization must preserve arithmetic grouping');
$assert($checksNormalizer->invoke(null,"((a AND (b OR c)))")!==$checksNormalizer->invoke(null,"((a AND b) OR c)"),'CHECK normalization must preserve AND/OR grouping');
$assert($checksNormalizer->invoke(null,"regexp_like(hash,'[0-9a-f`]{64}')")!==$checksNormalizer->invoke(null,"regexp_like(hash,'[0-9a-f]{64}')"),'CHECK normalization must preserve literal backticks');
$assert($checksNormalizer->invoke(null,"(case when ((subject_type='daily') or (status in ('reserved','settled'))) then transfer_amount else NULL end)")!==$checksNormalizer->invoke(null,"(case when ((subject_type='daily' or status) in ('reserved','settled')) then transfer_amount else NULL end)"),'Generated amount normalization must preserve OR/IN grouping');
fwrite(STDOUT,"{$checks} daily booking unit checks passed; no database or provider calls\n");
