<?php
declare(strict_types=1);
use Dormitory\Integration\SlipVerifier;
// Unit checks only. No provider or database access.
$test('unknown slip service responses remain pending',function()use($same):void{
 foreach(['slipok','easyslip']as$provider)foreach([200,302,400,401,403,404,422]as$status){
  $same(true,SlipVerifier::isTransientProviderError($provider,null,$status));
  $same(true,SlipVerifier::isTransientProviderError($provider,'UNRECOGNIZED_CODE',$status));
 }
});
$test('network and authentication failures never reject bank evidence',function()use($same):void{
 foreach([0,401,403,408,429,500,502,503]as$status){
  $same(true,SlipVerifier::isTransientProviderError('slipok','1007',$status));
  $same(true,SlipVerifier::isTransientProviderError('easyslip','SLIP_NOT_FOUND',$status));
 }
});
$test('documented invalid slip responses retain their final classification',function()use($same):void{
 foreach(['1005','1006','1007','1008','1011','1013']as$code)$same(false,SlipVerifier::isTransientProviderError('slipok',$code,400));
 $same(false,SlipVerifier::isTransientProviderError('easyslip','SLIP_NOT_FOUND',404));
});
$easyFixture=static fn():array=>['_status'=>200,'success'=>true,'data'=>[
 'isDuplicate'=>false,'isAmountMatched'=>true,'amountInSlip'=>3500.00,
 'matchedAccount'=>['bankNumber'=>'1234567890'],
 'rawSlip'=>['transRef'=>'FIXTURE-001','date'=>'2026-09-20T12:00:00+07:00','countryCode'=>'TH',
 'amount'=>['amount'=>3500.00,'local'=>['amount'=>3500.00,'currency'=>'THB']],
 'receiver'=>['account'=>['bank'=>['account'=>'xxx-x-567890']]]]]];
$test('EasySlip safety flags require boolean values',function()use($app,$same,$easyFixture):void{
 $parse=new ReflectionMethod(SlipVerifier::class,'parseEasySlip');$service=new SlipVerifier($app);
 $same(true,$parse->invoke($service,$easyFixture())['ok']);
 foreach(['isDuplicate','isAmountMatched']as$field){
  foreach([null,'false','true',0,1,[]]as$value){
   $json=$easyFixture();$json['data'][$field]=$value;$result=$parse->invoke($service,$json);
   $same(false,$result['ok']);$same(true,$result['transient']);
  }
 }
});
$test('EasySlip duplicate data and inconsistent amounts are not accepted',function()use($app,$same,$easyFixture):void{
 $parse=new ReflectionMethod(SlipVerifier::class,'parseEasySlip');$service=new SlipVerifier($app);
 $json=$easyFixture();$json['data']['isDuplicate']=true;$same(true,$parse->invoke($service,$json)['ambiguous_duplicate']);
 $json=$easyFixture();$json['data']['rawSlip']['amount']['amount']=1;$same(false,$parse->invoke($service,$json)['ok']);
 $json=$easyFixture();$json['data']['isAmountMatched']=false;$same(false,$parse->invoke($service,$json)['ok']);
});
$test('provider scalar parsing excludes boolean and composite values',function()use($app,$same):void{
 $method=new ReflectionMethod(SlipVerifier::class,'scalarString');$service=new SlipVerifier($app);
 foreach([true,false,[],NAN,INF]as$value)$same(null,$method->invoke($service,$value));
 $same('100',$method->invoke($service,100));
});
$test('provider error codes in audit output are restricted to known values',function()use($app,$same):void{
 $method=new ReflectionMethod(SlipVerifier::class,'auditPayload');$service=new SlipVerifier($app);
 foreach([['unknown'=>'private-detail'],'private-detail',str_repeat('A',10000)]as$value){
  $same(null,$method->invoke($service,['provider_code'=>$value])['provider_code']);
 }
 $same('1010',$method->invoke($service,['provider_code'=>1010])['provider_code']);
});
$test('empty provider responses cannot become final rejections',function()use($app,$same):void{
 $easy=new ReflectionMethod(SlipVerifier::class,'parseEasySlip');$slipok=new ReflectionMethod(SlipVerifier::class,'parseSlipOk');$service=new SlipVerifier($app);
 foreach([[],['_status'=>200],['_status'=>200,'success'=>true,'data'=>[]],['_status'=>401,'success'=>false]]as$json){
  foreach([$easy->invoke($service,$json),$slipok->invoke($service,$json,'fixture')]as$result){
   $same(false,$result['ok']);$same(true,$result['transient']);
  }
 }
});
