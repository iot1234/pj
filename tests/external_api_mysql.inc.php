<?php
declare(strict_types=1);
// Included inside billing_line_delivery_mysql.php's rollback-only transaction.
$deliveryId=(int)$ids[1];
$pdo->exec("UPDATE notification_outbox SET status='failed',sent_at=NULL,attempts=2 WHERE id=".$deliveryId);
$original=$pdo->query('SELECT retry_key,payload,recipient,created_at,attempts FROM notification_outbox WHERE id='.$deliveryId)->fetch();
$app->notifications()->enqueueBill($billId);
$retried=$pdo->query('SELECT retry_key,payload,recipient,created_at,attempts FROM notification_outbox WHERE id='.$deliveryId)->fetch();
$check('Manual retry preserves all original LINE request fields and attempt history',$original===$retried);
$pdo->exec("UPDATE notification_outbox SET status='failed',created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 25 HOUR) WHERE id=".$deliveryId);
$expired=false;
try{$app->notifications()->enqueueBill($billId);}catch(Dormitory\Http\HttpException $e){$expired=$e->errorCode==='LINE_RETRY_WINDOW_EXPIRED';}
$check('Expired LINE requests cannot silently acquire a new retry key',$expired);
$check('Expired request identity is unchanged',$pdo->query('SELECT retry_key FROM notification_outbox WHERE id='.$deliveryId)->fetchColumn()===$original['retry_key']);
$pdo->exec("UPDATE notification_outbox SET status='sent',sent_at=UTC_TIMESTAMP(6) WHERE id=".$deliveryId);
$app->settings()->update(['slip_provider'=>'slipok','slipok_branch_id'=>'fixture-branch','slipok_api_key'=>'fixture-slip-key','payment_receiver_account_tail'=>'567890'],$owner);
$providerReply=['_status'=>200,'success'=>true,'data'=>['success'=>true,'transRef'=>'OFFLINE-'.$suffix,
 'amount'=>3500,'countryCode'=>'TH','paidLocalCurrency'=>'THB','transTimestamp'=>gmdate('Y-m-d\TH:i:s\Z'),
 'receiver'=>['account'=>['value'=>'xxx-x-567890']]]];
$calls=0;
$slip=new Dormitory\Integration\SlipVerifier($app,static function(string $url,array $headers,array $body)use(&$calls,&$providerReply,$check):array{
 $calls++;$check('SlipOK uses its fixed endpoint with receiver logging and exact expected amount',
 $url==='https://api.slipok.com/api/line/apikey/fixture-branch'&&$body['log']==='true'&&$body['amount']==='3500.00');
 return $providerReply;
});
$result=$slip->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
$check('Complete provider evidence verifies against a single settings snapshot',$result['decision']==='verified'&&strlen($result['settings_fingerprint'])===64);
$check('Verification output does not contain the provider key',!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'fixture-slip-key'));
$before=$calls;
foreach([['_status'=>200],['_status'=>401,'success'=>false],['_status'=>503,'code'=>1007,'message'=>'private-provider-detail']]as$reply){
 $providerReply=$reply;$result=$slip->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
 $check('Provider outage or invalid response leaves evidence pending',$result['decision']==='pending');
 $check('Provider diagnostics are not returned to residents',!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'private-provider-detail'));
}
$check('Each verification makes one provider request without automatic resubmission',$calls===$before+3);
$providerReply=['_status'=>400,'success'=>false,'code'=>1012];
$result=$slip->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
$check('Provider duplicate evidence stays available for reconciliation',$result['decision']==='pending');
$providerReply=['_status'=>400,'success'=>false,'code'=>['private'=>'private-provider-detail']];
$result=$slip->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
$check('Composite provider codes cannot enter persisted audit output',$result['decision']==='pending'&&$result['payload']['provider_code']===null);
$failing=new Dormitory\Integration\SlipVerifier($app,static function():never{throw new RuntimeException('private-network-diagnostic');});
$result=$failing->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
$check('Transport exceptions are controlled and recoverable',$result['decision']==='pending'&&!str_contains(json_encode($result,JSON_THROW_ON_ERROR),'private-network-diagnostic'));
$snapshot=$app->settings()->slipVerificationSettings();
$app->settings()->update(['promptpay_name'=>'Unrelated display change'],$owner);
$check('Unrelated display settings do not change the slip configuration fingerprint',$snapshot['fingerprint']===$app->settings()->slipVerificationSettings()['fingerprint']);
$app->settings()->update(['payment_receiver_account_tail'=>'678901'],$owner);
$check('Receiver change produces a different verification configuration',$snapshot['fingerprint']!==$app->settings()->slipVerificationSettings()['fingerprint']);
$changesDuringRequest=new Dormitory\Integration\SlipVerifier($app,static function()use($app,$owner):array{
 $app->settings()->update(['payment_receiver_account_tail'=>'567890'],$owner);
 return ['_status'=>200,'success'=>true,'data'=>['success'=>true,'transRef'=>'CONCURRENT-FIXTURE',
  'amount'=>3500,'countryCode'=>'TH','paidLocalCurrency'=>'THB','transTimestamp'=>gmdate('Y-m-d\TH:i:s\Z'),
  'receiver'=>['account'=>['value'=>'xxx-x-678901']]]];
});
$result=$changesDuringRequest->verify('offline-fixture.png','image/png','3500.00',gmdate('Y-m-d H:i:s',time()-60));
$check('Configuration changed during a provider request requires rechecking evidence',
 $result['decision']==='pending'&&($result['payload']['configuration_changed']??false)===true);
