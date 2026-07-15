<?php
declare(strict_types=1);

namespace Dormitory\Integration;

use Dormitory\Application;
use Dormitory\Domain\PromptPayService;
use Dormitory\Support\Validator;

final class SlipVerifier
{
    public function __construct(private readonly Application $app) {}

    /** @return array{decision:string,provider:?string,transaction_ref:?string,receiver_ref:?string,payload:array<string,mixed>,reason:?string} */
    public function verify(string $path,string $mime,string $expectedAmount,string $billCreatedAt): array
    {
        $provider=strtolower(trim((string)$this->app->settings()->value('slip_provider','none')));
        if(!in_array($provider,['slipok','easyslip','none'],true))return $this->pending(null,'Unsupported SLIP_PROVIDER',[]);
        if($provider==='none')return $this->pending(null,'Slip verification provider is not configured',[]);
        try{$raw=$provider==='slipok'?$this->slipOk($path,$mime,$expectedAmount):$this->easySlip($path,$mime,$expectedAmount);}
        catch(\Throwable $e){return $this->pending($provider,'Provider unavailable: '.substr($e->getMessage(),0,300),[]);}
        if(!($raw['ok']??false)){
            // A duplicate response is ambiguous after a provider accepted a
            // request but the local process crashed before committing the
            // result. Preserve the reserved payment for review/retry instead
            // of incorrectly turning potentially valid evidence into a final
            // rejection.
            if(($raw['ambiguous_duplicate']??false)===true){
                return $this->pending($provider,(string)($raw['reason']??'Provider reports this slip was already checked'),$this->auditPayload($raw));
            }
            $transient=(bool)($raw['transient']??false);
            return $transient?$this->pending($provider,(string)($raw['reason']??'Provider error'),$this->auditPayload($raw)): [
                'decision'=>'rejected','provider'=>$provider,'transaction_ref'=>null,'receiver_ref'=>null,'payload'=>$this->auditPayload($raw),'reason'=>(string)($raw['reason']??'Slip was rejected'),
            ];
        }
        $transaction=trim((string)($raw['transaction_ref']??''));$receiver=trim((string)($raw['receiver_ref']??''));
        $matchedAccountReference=trim((string)($raw['matched_account_ref']??''));
        $time=self::evaluateTransactionTime($raw['transferred_at']??null,$billCreatedAt,$this->app->settings()->intValue('slip_time_tolerance_seconds',300));
        $raw['transferred_at']=$time['transferred_at'];
        $audit=$this->auditPayload($raw);
        if($transaction===''||strlen($transaction)>191||preg_match('/[\x00-\x1F\x7F]/',$transaction))return $this->pending($provider,'Provider response has no valid transaction reference',$audit);
        if(strlen($receiver)>191||preg_match('/[\x00-\x1F\x7F]/',$receiver))return $this->pending($provider,'Provider response has no valid receiver reference',$audit);
        if(strlen($matchedAccountReference)>191||preg_match('/[\x00-\x1F\x7F]/',$matchedAccountReference))return $this->pending($provider,'Provider response has no valid matched account reference',$audit);
        if($time['decision']==='pending')return $this->pending($provider,(string)$time['reason'],$audit);
        if($time['decision']==='rejected')return ['decision'=>'rejected','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>(string)$time['reason']];
        try{$expected=Validator::scaledDecimal($expectedAmount,'expected_amount',2,12);$actual=Validator::scaledDecimal($raw['amount']??'','provider_amount',2,12);}catch(\Throwable){return $this->pending($provider,'Provider response has an invalid amount',$audit);}
        if($actual!==$expected)return ['decision'=>'rejected','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>'Slip amount does not match the bill'];
        $tail=trim((string)$this->app->settings()->value('payment_receiver_account_tail',''));
        if($tail==='')return $this->pending($provider,'Payment receiver is not configured',$audit);
        $configuredBranch=$provider==='slipok'?trim((string)$this->app->settings()->value('slipok_branch_id','')):null;
        $providerMatched=($raw['account_matched']??false)===true;
        $receiverTrusted=PromptPayService::providerReceiverMatches(
            $provider,
            $tail,
            $matchedAccountReference,
            $providerMatched,
            $configuredBranch,
            is_string($raw['provider_branch']??null)?$raw['provider_branch']:null,
        );
        if(!$receiverTrusted){
            $reason=$provider==='slipok'
                ? 'SlipOK did not confirm the receiver for the configured API branch'
                : 'EasySlip matched account does not equal the configured receiver account';
            // A receiver mismatch must never mark the bill paid. Keep the
            // reservation recoverable because an owner may have entered the
            // receiver tail/branch incorrectly; after correcting settings an
            // admin can retry the same immutable evidence or close it.
            return $this->pending($provider,$reason,$audit);
        }
        return ['decision'=>'verified','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>null];
    }

    /** @return array<string,mixed> */
    private function slipOk(string $path,string $mime,string $amount): array
    {
        $key=trim((string)$this->app->settings()->value('slipok_api_key',''));
        if($key==='')throw new \RuntimeException('SlipOK API key is not configured');
        $branch=trim((string)$this->app->settings()->value('slipok_branch_id',''));
        if($branch===''||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$branch))throw new \RuntimeException('SlipOK branch ID is missing or invalid');
        if(!class_exists(\CURLFile::class))throw new \RuntimeException('PHP cURL extension is required');
        $json=$this->request(
            'https://api.slipok.com/api/line/apikey/'.rawurlencode($branch),
            ['x-authorization: '.$key],
            ['files'=>new \CURLFile($path,$mime,'slip'),'log'=>'true','amount'=>$amount],
        );
        $d=is_array($json['data']??null)?$json['data']:[];
        $status=(int)($json['_status']??0);
        $providerCode=is_scalar($json['code']??null)?strtoupper(trim((string)$json['code'])):'';
        $success=$status===200&&($json['success']??false)===true&&($d['success']??false)===true&&$d!==[];
        // SlipOK documents that error 1012 includes the complete slip data.
        // Reconcile that cached evidence exactly like a success response. This
        // recovers a reservation after the provider accepted it but PHP died
        // before the local transaction could be finalized.
        $recoverableDuplicate=$status===400&&$providerCode==='1012'&&$d!==[]&&($d['success']??false)===true;
        if(!$success&&!$recoverableDuplicate){
            $contractIncomplete=$status===200&&($json['success']??false)===true&&(!$success||$d===[]);
            return [
                'ok'=>false,
                'transient'=>$contractIncomplete||self::isTransientProviderError('slipok',$json['code']??null,$status),
                'reason'=>$contractIncomplete?'SlipOK returned an incomplete receiver-verification contract':$this->providerReason($json,'SlipOK rejected the slip'),
                'provider_code'=>$json['code']??null,
                'http_status'=>$status,
                'ambiguous_duplicate'=>$providerCode==='1012'||str_contains(strtoupper((string)($json['message']??'')),'DUPLICATE'),
            ];
        }
        $receiver=$d['receiver']['account']['value']??$d['receiver']['account']??$d['receiver']['proxy']['value']??null;
        return [
            'ok'=>true,
            'transaction_ref'=>$this->scalarString($d['transRef']??$d['ref']??$d['transactionRef']??null),
            'amount'=>$this->scalarString($d['amount']??null),
            'receiver_ref'=>$this->scalarString($receiver),
            // With log=true, a successful response means SlipOK has matched
            // the receiver against the bank account registered for this API
            // branch. Persist the local branch identifier in the audit data;
            // never attempt a six-digit comparison against SlipOK's masked
            // receiver.account.value.
            'account_matched'=>true,
            'receiver_match_source'=>'slipok_branch_log',
            'provider_branch'=>$branch,
            'transferred_at'=>self::extractTransferredAt('slipok',$d),
            'http_status'=>$status,
            'provider_code'=>$recoverableDuplicate?'1012':null,
        ];
    }

    /** @return array<string,mixed> */
    private function easySlip(string $path,string $mime,string $amount): array
    {
        $key=trim((string)$this->app->settings()->value('easyslip_api_key',''));
        if($key==='')throw new \RuntimeException('EasySlip API key is not configured');
        if(!class_exists(\CURLFile::class))throw new \RuntimeException('PHP cURL extension is required');
        $json=$this->request(
            'https://api.easyslip.com/v2/verify/bank',
            ['Authorization: Bearer '.$key],
            ['image'=>new \CURLFile($path,$mime,'slip'),'checkDuplicate'=>'true','matchAmount'=>$amount,'matchAccount'=>'true'],
        );
        $d=is_array($json['data']??null)?$json['data']:[];
        $raw=is_array($d['rawSlip']??null)?$d['rawSlip']:[];
        $status=(int)($json['_status']??0);
        $providerCode=$json['error']['code']??$json['code']??null;
        $duplicate=is_scalar($providerCode)&&str_contains(strtoupper((string)$providerCode),'DUPLICATE');
        if($status!==200||($json['success']??false)!==true||$d===[]){
            return [
                'ok'=>false,
                'transient'=>!$duplicate&&self::isTransientProviderError('easyslip',$providerCode,$status),
                'reason'=>$duplicate?'The provider reports that this slip was already used':$this->providerReason($json,'EasySlip rejected the slip'),
                'provider_code'=>$providerCode,
                'http_status'=>$status,
                'ambiguous_duplicate'=>$duplicate,
            ];
        }
        // EasySlip returns a normal 200 response with the complete cached slip
        // contract and isDuplicate=true. Validate it below; transaction_ref and
        // the local slip HMAC uniqueness guards still prevent cross-bill replay.
        $wasDuplicate=($d['isDuplicate']??false)===true;
        $matchedAccount=is_array($d['matchedAccount']??null)?$d['matchedAccount']:null;
        $matchedAccountReference=$matchedAccount['bankNumber']??null;
        // Keep the provider's masked slip reference for display/forensics, but
        // authorize the receiver only with the full registered matchedAccount
        // reference. Falling back to the raw masked value would make a secure
        // six-digit comparison impossible.
        $receiver=$raw['receiver']['account']['bank']['account']??$raw['receiver']['account']['proxy']['account']??null;
        $rawAmount=is_array($raw['amount']??null)?($raw['amount']['amount']??null):($raw['amount']??null);
        return [
            'ok'=>true,
            'transaction_ref'=>$this->scalarString($raw['transRef']??$d['transRef']??null),
            'amount'=>$this->scalarString($d['amountInSlip']??$rawAmount??$d['amount']??null),
            'receiver_ref'=>$this->scalarString($receiver),
            'account_matched'=>$matchedAccount!==null,
            'matched_account_ref'=>$this->scalarString($matchedAccountReference),
            'receiver_match_source'=>'easyslip_registered_account',
            'transferred_at'=>self::extractTransferredAt('easyslip',$d),
            'http_status'=>$status,
            'provider_code'=>$wasDuplicate?'DUPLICATE':null,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function extractTransferredAt(string $provider,array $data): ?string
    {
        if($provider==='slipok'){
            $timestamp=$data['transTimestamp']??null;
            if(is_string($timestamp)&&trim($timestamp)!=='')return trim($timestamp);
            $date=$data['transDate']??null;$time=$data['transTime']??null;
            if(is_string($date)&&is_string($time)&&preg_match('/^\d{8}$/D',trim($date))&&preg_match('/^\d{2}:\d{2}:\d{2}$/D',trim($time))){$date=trim($date);return substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2).'T'.trim($time).'+07:00';}
            return null;
        }
        if($provider==='easyslip'){
            $raw=is_array($data['rawSlip']??null)?$data['rawSlip']:[];$date=$raw['date']??null;
            return is_string($date)&&trim($date)!==''?trim($date):null;
        }
        return null;
    }

    /** @return array{decision:string,transferred_at:?string,reason:?string} */
    public static function evaluateTransactionTime(mixed $transferredAt,string $billCreatedAt,int $toleranceSeconds,?\DateTimeImmutable $now=null): array
    {
        $transfer=self::parseOffsetTimestamp($transferredAt);
        if(!$transfer)return ['decision'=>'pending','transferred_at'=>null,'reason'=>'Provider response has no valid transfer time'];
        $normalized=$transfer->format('Y-m-d\TH:i:s.u\Z');
        $bill=self::parseUtcDatabaseTimestamp($billCreatedAt);
        if(!$bill)return ['decision'=>'pending','transferred_at'=>$normalized,'reason'=>'Bill creation time is invalid'];
        $tolerance=max(0,min(3600,$toleranceSeconds));$interval=new \DateInterval('PT'.$tolerance.'S');
        $current=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        if($transfer<$bill->sub($interval))return ['decision'=>'rejected','transferred_at'=>$normalized,'reason'=>'Slip transfer predates the bill'];
        if($transfer>$current->add($interval))return ['decision'=>'rejected','transferred_at'=>$normalized,'reason'=>'Slip transfer time is in the future'];
        return ['decision'=>'valid','transferred_at'=>$normalized,'reason'=>null];
    }

    private static function parseOffsetTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if(!is_string($value))return null;$value=trim($value);
        if(strlen($value)>64||!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/D',$value,$parts))return null;
        if($parts[3]!=='Z'){$offsetHour=(int)substr($parts[3],1,2);$offsetMinute=(int)substr($parts[3],4,2);if($offsetHour>14||$offsetMinute>59||($offsetHour===14&&$offsetMinute!==0))return null;}
        $fraction=str_pad($parts[2]??'',6,'0');$offset=$parts[3]==='Z'?'+00:00':$parts[3];$canonical=$parts[1].'.'.$fraction.$offset;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP',$canonical,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();
        if(!$parsed||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$parsed->format('Y-m-d\TH:i:s.uP')!==$canonical)return null;
        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function parseUtcDatabaseTimestamp(string $value): ?\DateTimeImmutable
    {
        $value=trim($value);
        if(strlen($value)>32||!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D',$value,$parts))return null;
        $canonical=$parts[1].'.'.str_pad($parts[2]??'',6,'0');$utc=new \DateTimeZone('UTC');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$canonical,$utc);$errors=\DateTimeImmutable::getLastErrors();
        if(!$parsed||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$parsed->format('Y-m-d H:i:s.u')!==$canonical)return null;
        return $parsed;
    }

    public static function isTransientProviderError(string $provider,mixed $code,int $status): bool
    {
        $normalized=strtoupper(trim(is_scalar($code)?(string)$code:''));
        if($provider==='slipok'){
            // 1014 means the receiver account does not match the configured
            // SlipOK branch. Keep it recoverable so an owner can correct the
            // branch/receiver setting and retry the same immutable evidence.
            if(in_array($normalized,['1000','1001','1002','1003','1004','1009','1010','1014'],true))return true;
            if(preg_match('/^10(?:0[5-9]|1[1-4])$/',$normalized))return false;
        }
        if($provider==='easyslip'){
            if(in_array($normalized,['SLIP_PENDING','API_SERVER_ERROR','INTERNAL_SERVER_ERROR','NOT_FOUND'],true))return true;
            if(in_array($normalized,['SLIP_NOT_FOUND','VALIDATION_ERROR','INVALID_IMAGE_TYPE','INVALID_IMAGE_FORMAT','IMAGE_SIZE_TOO_LARGE','DUPLICATE_SLIP'],true))return false;
            if(in_array($status,[401,403],true))return true;
            if($status===404)return $normalized==='SLIP_PENDING';
        }
        return $status===0||$status>=500||in_array($status,[408,425,429],true);
    }

    /** @param array<string,mixed> $payload */
    private function providerReason(array $payload,string $fallback): string
    {
        $candidate=$payload['message']??(is_array($payload['error']??null)?($payload['error']['message']??null):($payload['error']??null));
        return is_scalar($candidate)&&trim((string)$candidate)!==''?substr(trim((string)$candidate),0,300):$fallback;
    }

    private function scalarString(mixed $value): ?string
    {
        return is_scalar($value)?(string)$value:null;
    }

    /** @param list<string> $headers @param array<string,mixed>|string $body @return array<string,mixed> */
    private function request(string $url,array $headers,array|string $body): array
    {
        if(!function_exists('curl_init'))throw new \RuntimeException('PHP cURL extension is required');
        $ch=curl_init($url);if($ch===false)throw new \RuntimeException('Cannot initialize cURL');
        $response='';$tooLarge=false;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{if(strlen($response)+strlen($chunk)>262144){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
        $executed=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($tooLarge)throw new \RuntimeException('Provider response exceeded limit');if($executed===false)throw new \RuntimeException($error?:'Request failed');
        try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Provider returned malformed JSON');}
        if(!is_array($decoded))throw new \RuntimeException('Provider returned an invalid response');$decoded['_status']=$status;return $decoded;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function auditPayload(array $payload): array
    {
        $receiverValue=is_scalar($payload['receiver_ref']??null)?(string)$payload['receiver_ref']:'';
        $receiver=preg_replace('/\D+/','',$receiverValue)??'';
        $matchedValue=is_scalar($payload['matched_account_ref']??null)?(string)$payload['matched_account_ref']:'';
        $matched=preg_replace('/\D+/','',$matchedValue)??'';
        $amount=is_scalar($payload['amount']??null)?(string)$payload['amount']:null;
        $transferredAt=is_string($payload['transferred_at']??null)?$payload['transferred_at']:null;
        $branch=is_string($payload['provider_branch']??null)&&preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$payload['provider_branch'])?$payload['provider_branch']:null;
        $source=is_string($payload['receiver_match_source']??null)&&in_array($payload['receiver_match_source'],['slipok_branch_log','easyslip_registered_account'],true)?$payload['receiver_match_source']:null;
        return ['ok'=>(bool)($payload['ok']??false),'http_status'=>$payload['http_status']??null,'provider_code'=>$payload['provider_code']??null,'amount'=>$amount,'receiver_tail'=>$receiver!==''?substr($receiver,-6):null,'matched_receiver_tail'=>$matched!==''?substr($matched,-6):null,'account_matched'=>$payload['account_matched']??null,'receiver_match_source'=>$source,'provider_branch'=>$branch,'transferred_at'=>$transferredAt];
    }

    /** @param array<string,mixed> $payload @return array{decision:string,provider:?string,transaction_ref:?string,receiver_ref:?string,payload:array<string,mixed>,reason:string} */
    private function pending(?string $provider,string $reason,array $payload): array{if(!array_key_exists('transferred_at',$payload))$payload['transferred_at']=null;return ['decision'=>'pending','provider'=>$provider,'transaction_ref'=>null,'receiver_ref'=>null,'payload'=>$payload,'reason'=>$reason];}
}
