<?php
declare(strict_types=1);

namespace Dormitory\Integration;

use Dormitory\Application;
use Dormitory\Domain\PromptPayService;
use Dormitory\Support\Validator;

final class SlipVerifier
{
    public function __construct(private readonly Application $app, private readonly ?\Closure $transport = null) {
        if($transport!==null&&(PHP_SAPI!=='cli'||$app->config->get('APP_ENV')!=='testing'))throw new \InvalidArgumentException('Slip transport injection is restricted to CLI tests');
    }

    /** @return array{decision:string,provider:?string,transaction_ref:?string,receiver_ref:?string,payload:array<string,mixed>,reason:?string} */
    public function verify(string $path,string $mime,string $expectedAmount,string $billCreatedAt): array
    {
        try {
            $settings=$this->app->settings()->slipVerificationSettings();
            $result=$this->verifyWithSettings($path,$mime,$expectedAmount,$billCreatedAt,$settings);
            $latest=$this->app->settings()->slipVerificationSettings();
            if(!hash_equals($settings['fingerprint'],$latest['fingerprint'])){
                return $this->pending($settings['provider'],'การตั้งค่าตรวจสลิปเปลี่ยนระหว่างรอผล เก็บสลิปไว้ตรวจใหม่ ไม่ต้องโอนซ้ำ',['configuration_changed'=>true]);
            }
            return $result+['settings_fingerprint'=>$settings['fingerprint']];
        } catch(\Throwable) { return $this->pending(null,'อ่านการตั้งค่าตรวจสลิปไม่สำเร็จ กรุณาให้ผู้ดูแลตรวจสอบ โดยไม่ต้องโอนซ้ำ',[]); }
    }

    private function verifyWithSettings(string $path,string $mime,string $expectedAmount,string $billCreatedAt,array $settings): array
    {
        $provider=$settings['provider'];
        if(!in_array($provider,['slipok','easyslip','none'],true))return $this->pending(null,'Unsupported SLIP_PROVIDER',[]);
        if($provider==='none')return $this->pending(null,'Slip verification provider is not configured',[]);
        try{$raw=$provider==='slipok'?$this->slipOk($path,$mime,$expectedAmount,$settings):$this->easySlip($path,$mime,$expectedAmount,$settings);}
        catch(\Throwable){return $this->pending($provider,'ผู้ให้บริการตรวจสลิปยังไม่พร้อม กรุณารอตรวจซ้ำ',[]);}
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
        $time=self::evaluateTransactionTime($raw['transferred_at']??null,$billCreatedAt,$settings['tolerance_seconds']);
        $raw['transferred_at']=$time['transferred_at'];
        $audit=$this->auditPayload($raw);
        if($transaction===''||strlen($transaction)>191||preg_match('/[\x00-\x1F\x7F]/',$transaction))return $this->pending($provider,'Provider response has no valid transaction reference',$audit);
        if(strlen($receiver)>191||preg_match('/[\x00-\x1F\x7F]/',$receiver))return $this->pending($provider,'Provider response has no valid receiver reference',$audit);
        if(strlen($matchedAccountReference)>191||preg_match('/[\x00-\x1F\x7F]/',$matchedAccountReference))return $this->pending($provider,'Provider response has no valid matched account reference',$audit);
        $locale=self::evaluatePaymentLocale($provider,$raw['country_code']??null,$raw['currency']??null);
        if($locale['decision']==='pending')return $this->pending($provider,(string)$locale['reason'],$audit);
        if($locale['decision']==='rejected')return ['decision'=>'rejected','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>(string)$locale['reason']];
        if($time['decision']==='pending')return $this->pending($provider,(string)$time['reason'],$audit);
        if($time['decision']==='rejected')return ['decision'=>'rejected','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>(string)$time['reason']];
        try{$expected=Validator::scaledDecimal($expectedAmount,'expected_amount',2,12);$actual=Validator::scaledDecimal($raw['amount']??'','provider_amount',2,12);}catch(\Throwable){return $this->pending($provider,'Provider response has an invalid amount',$audit);}
        if($actual!==$expected)return ['decision'=>'rejected','provider'=>$provider,'transaction_ref'=>$transaction,'receiver_ref'=>$receiver?:null,'payload'=>$audit,'reason'=>'Slip amount does not match the bill'];
        $tail=$settings['receiver_tail'];
        if($tail==='')return $this->pending($provider,'Payment receiver is not configured',$audit);
        $configuredBranch=$provider==='slipok'?$settings['branch_id']:null;
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
    private function slipOk(string $path,string $mime,string $amount,array $settings): array
    {
        $key=$settings['key'];
        if($key==='')throw new \RuntimeException('SlipOK API key is not configured');
        $branch=$settings['branch_id'];
        if($branch===''||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$branch))throw new \RuntimeException('SlipOK branch ID is missing or invalid');
        if(!class_exists(\CURLFile::class))throw new \RuntimeException('PHP cURL extension is required');
        $json=$this->request(
            'https://api.slipok.com/api/line/apikey/'.rawurlencode($branch),
            ['x-authorization: '.$key],
            ['files'=>new \CURLFile($path,$mime,self::uploadFilenameForMime($mime)),'log'=>'true','amount'=>$amount],
        );
        return $this->parseSlipOk($json,$branch);
    }

    private function parseSlipOk(array $json,string $branch): array
    {
        $d=is_array($json['data']??null)?$json['data']:[];
        $status=(int)($json['_status']??0);
        $providerCode=is_scalar($json['code']??null)?strtoupper(trim((string)$json['code'])):'';
        $receiver=$d['receiver']['account']['value']??$d['receiver']['account']??$d['receiver']['proxy']['value']??null;
        // Error 1012 can include complete slip data, but the provider cannot
        // prove whether this application, another bill, or another client made
        // the original check. Never auto-pay from cached duplicate evidence.
        if($providerCode==='1012'){
            return [
                'ok'=>false,
                'transient'=>false,
                'reason'=>'SlipOK reports a duplicate slip; manual reconciliation is required',
                'provider_code'=>'1012',
                'http_status'=>$status,
                'ambiguous_duplicate'=>true,
                'amount'=>$this->scalarString($d['amount']??null),
                'receiver_ref'=>$this->scalarString($receiver),
                'account_matched'=>($d['success']??false)===true,
                'receiver_match_source'=>'slipok_branch_log',
                'provider_branch'=>$branch,
                'transferred_at'=>self::extractTransferredAt('slipok',$d),
                'country_code'=>$this->scalarString($d['countryCode']??null),
                'currency'=>$this->scalarString($d['paidLocalCurrency']??null),
            ];
        }
        $success=$status===200&&($json['success']??false)===true&&($d['success']??false)===true&&$d!==[]&&$providerCode==='';
        if(!$success){
            $contractIncomplete=$status===200&&($json['success']??false)===true&&(!$success||$d===[]);
            return [
                'ok'=>false,
                'transient'=>$contractIncomplete||self::isTransientProviderError('slipok',$json['code']??null,$status),
                'reason'=>$contractIncomplete?'SlipOK returned an incomplete receiver-verification contract':$this->providerReason($json,self::isTransientProviderError('slipok',$json['code']??null,$status)?'บริการตรวจสลิปยังยืนยันผลไม่ได้ เก็บสลิปไว้ตรวจต่อ ไม่ต้องโอนซ้ำ':'ตรวจสลิปไม่ผ่าน กรุณาตรวจหลักฐานและติดต่อผู้ดูแล'),
                'provider_code'=>$json['code']??null,
                'http_status'=>$status,
                'ambiguous_duplicate'=>$providerCode==='1012'||(is_string($json['message']??null)&&str_contains(strtoupper($json['message']),'DUPLICATE')),
            ];
        }
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
            'country_code'=>$this->scalarString($d['countryCode']??null),
            'currency'=>$this->scalarString($d['paidLocalCurrency']??null),
            'http_status'=>$status,
            'provider_code'=>null,
        ];
    }

    /** @return array<string,mixed> */
    private function easySlip(string $path,string $mime,string $amount,array $settings): array
    {
        $key=$settings['key'];
        if($key==='')throw new \RuntimeException('EasySlip API key is not configured');
        if(!class_exists(\CURLFile::class))throw new \RuntimeException('PHP cURL extension is required');
        $json=$this->request(
            'https://api.easyslip.com/v2/verify/bank',
            ['Authorization: Bearer '.$key],
            ['image'=>new \CURLFile($path,$mime,self::uploadFilenameForMime($mime)),'checkDuplicate'=>'true','matchAmount'=>$amount,'matchAccount'=>'true'],
        );
        return $this->parseEasySlip($json);
    }

    private function parseEasySlip(array $json): array
    {
        $d=is_array($json['data']??null)?$json['data']:[];
        $raw=is_array($d['rawSlip']??null)?$d['rawSlip']:[];
        $status=(int)($json['_status']??0);
        $providerCode=$json['error']['code']??$json['code']??null;
        $duplicate=is_scalar($providerCode)&&str_contains(strtoupper((string)$providerCode),'DUPLICATE');
        if($status!==200||($json['success']??false)!==true||$d===[]){
            return [
                'ok'=>false,
                'transient'=>!$duplicate&&self::isTransientProviderError('easyslip',$providerCode,$status),
                'reason'=>$duplicate?'EasySlip reports a duplicate slip; manual reconciliation is required':$this->providerReason($json,self::isTransientProviderError('easyslip',$providerCode,$status)?'บริการตรวจสลิปยังยืนยันผลไม่ได้ เก็บสลิปไว้ตรวจต่อ ไม่ต้องโอนซ้ำ':'ตรวจสลิปไม่ผ่าน กรุณาตรวจหลักฐานและติดต่อผู้ดูแล'),
                'provider_code'=>$providerCode,
                'http_status'=>$status,
                'ambiguous_duplicate'=>$duplicate,
            ];
        }
        if(!is_bool($d['isDuplicate']??null))return $this->incompleteContract($status);
        $wasDuplicate=($d['isDuplicate']??false)===true;
        $matchedAccount=is_array($d['matchedAccount']??null)?$d['matchedAccount']:null;
        $matchedAccountReference=$matchedAccount['bankNumber']??null;
        // Keep the provider's masked slip reference for display/forensics, but
        // authorize the receiver only with the full registered matchedAccount
        // reference. Falling back to the raw masked value would make a secure
        // six-digit comparison impossible.
        $receiver=$raw['receiver']['account']['bank']['account']??$raw['receiver']['account']['proxy']['account']??null;
        $rawAmount=is_array($raw['amount']??null)?($raw['amount']['amount']??null):($raw['amount']??null);
        if($wasDuplicate){
            return [
                'ok'=>false,
                'transient'=>false,
                'reason'=>'EasySlip reports a duplicate slip; manual reconciliation is required',
                'provider_code'=>'DUPLICATE',
                'http_status'=>$status,
                'ambiguous_duplicate'=>true,
                'amount'=>$this->scalarString($d['amountInSlip']??$rawAmount??$d['amount']??null),
                'receiver_ref'=>$this->scalarString($receiver),
                'account_matched'=>$matchedAccount!==null,
                'matched_account_ref'=>$this->scalarString($matchedAccountReference),
                'receiver_match_source'=>'easyslip_registered_account',
                'transferred_at'=>self::extractTransferredAt('easyslip',$d),
                'country_code'=>$this->scalarString($raw['countryCode']??null),
                'currency'=>$this->scalarString(is_array($raw['amount']??null)?($raw['amount']['local']['currency']??null):null),
            ];
        }
        // Requested safety fields must be present with their documented types.
        if(!is_string($raw['transRef']??null)||!is_bool($d['isAmountMatched']??null)
            ||!array_key_exists('matchedAccount',$d)||($d['matchedAccount']!==null&&(!is_array($d['matchedAccount'])||!is_string($matchedAccountReference)))
            ||$this->scalarString($d['amountInSlip']??null)===null||$this->scalarString($rawAmount)===null)return $this->incompleteContract($status);
        try {
            $reported=Validator::scaledDecimal($d['amountInSlip'],'amount_in_slip',2,12);
            $rawReported=Validator::scaledDecimal($rawAmount,'raw_amount',2,12);
        } catch(\Throwable) { return $this->incompleteContract($status); }
        if($reported!==$rawReported||$d['isAmountMatched']!==true)return $this->incompleteContract($status);
        return [
            'ok'=>true,
            'transaction_ref'=>$this->scalarString($raw['transRef']??$d['transRef']??null),
            'amount'=>$this->scalarString($d['amountInSlip']??$rawAmount??$d['amount']??null),
            'receiver_ref'=>$this->scalarString($receiver),
            'account_matched'=>$matchedAccount!==null,
            'matched_account_ref'=>$this->scalarString($matchedAccountReference),
            'receiver_match_source'=>'easyslip_registered_account',
            'transferred_at'=>self::extractTransferredAt('easyslip',$d),
            'country_code'=>$this->scalarString($raw['countryCode']??null),
            'currency'=>$this->scalarString(is_array($raw['amount']??null)?($raw['amount']['local']['currency']??null):null),
            'http_status'=>$status,
            'provider_code'=>null,
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

    /** @return array{decision:string,reason:?string} */
    private static function evaluatePaymentLocale(string $provider,mixed $countryCode,mixed $currency): array
    {
        $country=is_scalar($countryCode)?strtoupper(trim((string)$countryCode)):'';
        $currencyCode=is_scalar($currency)?strtoupper(trim((string)$currency)):'';
        if($country==='')return ['decision'=>'pending','reason'=>'Provider response has no payment country'];
        if($country!=='TH')return ['decision'=>'rejected','reason'=>'Slip payment country is not Thailand'];
        // EasySlip v2 defines rawSlip.amount.local.currency as required. The
        // SlipOK contract marks paidLocalCurrency optional, so validate it
        // whenever present without rejecting otherwise valid domestic slips.
        if($provider==='easyslip'&&$currencyCode==='')return ['decision'=>'pending','reason'=>'Provider response has no payment currency'];
        if($currencyCode!==''&&!in_array($currencyCode,['THB','764'],true))return ['decision'=>'rejected','reason'=>'Slip payment currency is not THB'];
        return ['decision'=>'valid','reason'=>null];
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
        $normalized=is_string($code)||is_int($code)?strtoupper(trim((string)$code)):'';
        // Authentication, transport failures and service outages say nothing about a slip.
        if($status===0||$status>=500||in_array($status,[401,403,408,425,429],true))return true;
        if($provider==='slipok'&&in_array($status,[200,400,422],true)){
            return !in_array($normalized,['1005','1006','1007','1008','1011','1012','1013'],true);
        }
        if($provider==='easyslip'){
            if($status===404&&$normalized==='SLIP_NOT_FOUND')return false;
            if(in_array($status,[400,422],true)&&in_array($normalized,['INVALID_IMAGE_TYPE','INVALID_IMAGE_FORMAT','IMAGE_SIZE_TOO_LARGE','DUPLICATE_SLIP'],true))return false;
        }
        // Unknown/malformed contracts are recoverable; never ask the resident to pay again.
        return true;
    }

    /** @param array<string,mixed> $payload */
    private function providerReason(array $payload,string $fallback): string
    {
        // Provider messages are untrusted and can contain proxy, TLS or
        // request diagnostics. Keep the public/persisted reason bounded to a
        // controlled message; status and provider code remain in audit data.
        return $fallback;
    }

    private function incompleteContract(int $status): array
    {
        return ['ok'=>false,'transient'=>true,'http_status'=>$status,'provider_code'=>'INVALID_CONTRACT','reason'=>'ผู้ให้บริการส่งข้อมูลยืนยันไม่ครบหรือขัดแย้งกัน เก็บสลิปไว้ตรวจสอบต่อ ไม่ต้องโอนซ้ำ'];
    }

    private function scalarString(mixed $value): ?string
    {
        return is_string($value)||is_int($value)||(is_float($value)&&is_finite($value))?(string)$value:null;
    }

    private static function uploadFilenameForMime(string $mime): string
    {
        return match($mime){
            'image/jpeg'=>'slip.jpg',
            'image/png'=>'slip.png',
            'image/webp'=>'slip.webp',
            default=>throw new \InvalidArgumentException('Unsupported slip MIME type'),
        };
    }

    /** @param list<string> $headers @param array<string,mixed>|string $body @return array<string,mixed> */
    private function request(string $url,array $headers,array|string $body): array
    {
        if(!preg_match('~^https://(?:api\.slipok\.com/api/line/apikey/[A-Za-z0-9_-]{1,80}|api\.easyslip\.com/v2/verify/bank)$~D',$url))throw new \InvalidArgumentException('Unsupported slip endpoint');
        if($this->transport!==null)return ($this->transport)($url,$headers,$body);
        if(!function_exists('curl_init'))throw new \RuntimeException('PHP cURL extension is required');
        $ch=curl_init($url);if($ch===false)throw new \RuntimeException('Cannot initialize cURL');
        $response='';$tooLarge=false;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{if(strlen($response)+strlen($chunk)>262144){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
        $executed=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errorCode=(int)curl_errno($ch);curl_close($ch);
        if($tooLarge)throw new \RuntimeException('Provider response exceeded limit');if($executed===false)throw new \RuntimeException('Provider HTTPS request failed (curl '.$errorCode.')');
        try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('Provider returned malformed JSON');}
        if(!is_array($decoded)||array_is_list($decoded))throw new \RuntimeException('Provider returned an invalid response');$decoded['_status']=$status;return $decoded;
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
        $country=is_scalar($payload['country_code']??null)?strtoupper(substr(trim((string)$payload['country_code']),0,3)):null;
        $currency=is_scalar($payload['currency']??null)?strtoupper(substr(trim((string)$payload['currency']),0,4)):null;
        $rawCode=$payload['provider_code']??null;
        $code=is_int($rawCode)||is_string($rawCode)?strtoupper(trim((string)$rawCode)):null;
        $allowed=['1000','1001','1002','1003','1004','1005','1006','1007','1008','1009','1010','1011','1012','1013','1014','INVALID_CONTRACT','DUPLICATE','DUPLICATE_SLIP','SLIP_NOT_FOUND','SLIP_PENDING','INVALID_IMAGE_TYPE','INVALID_IMAGE_FORMAT','IMAGE_SIZE_TOO_LARGE','API_SERVER_ERROR','INTERNAL_SERVER_ERROR','NOT_FOUND','VALIDATION_ERROR','MISSING_API_KEY','INVALID_API_KEY','BRANCH_INACTIVE','SERVICE_BANNED','USER_BANNED','IP_NOT_ALLOWED','QUOTA_EXCEEDED'];
        if(!in_array($code,$allowed,true))$code=null;
        return ['ok'=>(bool)($payload['ok']??false),'http_status'=>$payload['http_status']??null,'provider_code'=>$code,'amount'=>$amount,'receiver_tail'=>$receiver!==''?substr($receiver,-6):null,'matched_receiver_tail'=>$matched!==''?substr($matched,-6):null,'account_matched'=>$payload['account_matched']??null,'receiver_match_source'=>$source,'provider_branch'=>$branch,'country_code'=>$country,'currency'=>$currency,'transferred_at'=>$transferredAt];
    }

    /** @param array<string,mixed> $payload @return array{decision:string,provider:?string,transaction_ref:?string,receiver_ref:?string,payload:array<string,mixed>,reason:string} */
    private function pending(?string $provider,string $reason,array $payload): array{if(!array_key_exists('transferred_at',$payload))$payload['transferred_at']=null;return ['decision'=>'pending','provider'=>$provider,'transaction_ref'=>null,'receiver_ref'=>null,'payload'=>$payload,'reason'=>$reason];}
}
