<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Integration\SlipVerifier;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

final class PaymentService
{
    private const MAX_IMAGE_DIMENSION = 4096;
    private const MAX_IMAGE_PIXELS = 8_000_000;

    private readonly SlipVerifier $verifier;
    public function __construct(private readonly Application $app){$this->verifier=new SlipVerifier($app);}

    /**
     * @param array<string,mixed> $file
     * @param null|callable(array<string,mixed>):void $afterFinalize
     * @return array<string,mixed>
     */
    public function upload(int $billId,int $residentId,array $file,?callable $afterFinalize=null): array
    {
        $settings=$this->app->settings()->publicSettings();
        if(($settings['slip_verification_ready']??false)!==true){
            throw new HttpException(503,'ระบบตรวจสลิปยังตั้งค่าไม่ครบ กรุณาติดต่อผู้ดูแล','SLIP_NOT_CONFIGURED');
        }
        $bill=$this->bill($billId,$residentId);if($bill['status']!=='pending')throw new HttpException(409,'บิลนี้ชำระแล้ว','BILL_ALREADY_PAID');
        [$absolute,$relative,$mime,$hmac]=$this->store($file,$residentId,$billId);
        $token=bin2hex(random_bytes(32));
        try{
            $payment=$this->reserve($bill,$residentId,$relative,$mime,$hmac,$token);
        }catch(\Throwable $e){if(is_file($absolute))@unlink($absolute);throw $e;}

        // The local reservation is committed before spending provider credit.
        // Concurrent uploads now stop at the unique/active-payment guards and
        // never race two provider decisions into the same bill.
        try{
            $verification=$this->verifier->verify($absolute,$mime,(string)$bill['total_amount'],(string)$bill['created_at']);
            return $this->finalizeReserved((int)$payment['id'],(int)$bill['id'],$token,$verification,$afterFinalize);
        }catch(\Throwable $error){
            try{$this->releaseClaim((int)$payment['id'],$token);}catch(\Throwable){error_log('Unable to release payment verification claim for payment '.$payment['id']);}
            throw $error;
        }
    }

    /** @return array{items:list<array<string,mixed>>,has_more:bool,next_offset:int} */
    public function list(?string $status=null,int $offset=0,int $limit=100): array
    {
        if($offset<0||$offset>1000000||$limit<1||$limit>200)throw new HttpException(422,'Invalid payment pagination','VALIDATION_ERROR');
        $params=[];$where='';if($status!==null&&$status!==''){$map=$status==='failed'?'rejected':$status;if(!in_array($map,['pending','verified','rejected'],true))throw new HttpException(422,'Invalid payment status','VALIDATION_ERROR');$where=' WHERE p.status=?';$params[]=$map;}
        $pageSize=$limit+1;
        $statement=$this->app->database()->pdo()->prepare("SELECT p.id,p.bill_id,p.resident_id,p.amount,p.status,p.provider,p.transaction_ref,p.receiver_ref,p.rejection_reason,p.verification_lease_until,p.verification_attempts,(p.status='pending' AND p.verification_lease_until IS NOT NULL AND p.verification_lease_until>UTC_TIMESTAMP()) AS verifying,p.created_at,p.updated_at,p.verified_at,b.bill_no,b.room_code_snapshot AS room_code,b.resident_name_snapshot AS full_name FROM payments p JOIN bills b ON b.id=p.bill_id".$where." ORDER BY (p.status='pending') DESC,p.created_at DESC,p.id DESC LIMIT {$pageSize} OFFSET {$offset}");
        $statement->execute($params);$rows=$statement->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);foreach($rows as &$row){foreach(['id','bill_id','resident_id','verification_attempts']as$key)$row[$key]=(int)$row[$key];$row['resident_name']=$row['full_name'];$row['verifying']=(bool)$row['verifying'];}
        return ['items'=>$rows,'has_more'=>$hasMore,'next_offset'=>$offset+count($rows)];
    }

    /**
     * @param null|callable(array<string,mixed>):void $afterFinalize
     * @return array<string,mixed>
     */
    public function retry(int $paymentId,?callable $afterFinalize=null): array
    {
        $settings=$this->app->settings()->publicSettings();
        if(($settings['slip_verification_ready']??false)!==true){
            throw new HttpException(503,'ระบบตรวจสลิปยังตั้งค่าไม่ครบ','SLIP_NOT_CONFIGURED');
        }
        $token=bin2hex(random_bytes(32));
        $claim=$this->claimRetry($paymentId,$token);
        try{
            $absolute=$this->storedSlipAbsolute((string)$claim['slip_path'],(string)$claim['slip_mime'],(string)$claim['slip_hmac']);
            $verification=$this->verifier->verify($absolute,(string)$claim['slip_mime'],(string)$claim['amount'],(string)$claim['bill_created_at']);
            return $this->finalizeReserved($paymentId,(int)$claim['bill_id'],$token,$verification,$afterFinalize);
        }catch(\Throwable $error){
            try{$this->releaseClaim($paymentId,$token);}catch(\Throwable){error_log('Unable to release payment verification claim for payment '.$paymentId);}
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function closePending(int $paymentId,array $input): array
    {
        Validator::only($input,['reason']);
        $reason=Validator::string($input['reason']??null,'reason',3,450);
        $lookup=$this->app->database()->pdo()->prepare('SELECT bill_id FROM payments WHERE id=?');
        $lookup->execute([$paymentId]);$reference=$lookup->fetch();
        if(!$reference)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');

        return $this->app->database()->transaction(function(PDO $pdo)use($paymentId,$reference,$reason):array{
            $billLock=$pdo->prepare('SELECT id,status FROM bills WHERE id=? FOR UPDATE');
            $billLock->execute([$reference['bill_id']]);
            if(!$billLock->fetch())throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            $paymentLock=$pdo->prepare("SELECT id,status,(verification_lease_until IS NOT NULL AND verification_lease_until>UTC_TIMESTAMP()) AS verifying FROM payments WHERE id=? AND bill_id=? FOR UPDATE");
            $paymentLock->execute([$paymentId,$reference['bill_id']]);$payment=$paymentLock->fetch();
            if(!$payment)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');
            if($payment['status']!=='pending')throw new HttpException(409,'รายการนี้มีผลตรวจสุดท้ายแล้ว','PAYMENT_NOT_PENDING',['status'=>$payment['status']]);
            if((bool)$payment['verifying'])throw new HttpException(409,'รายการกำลังตรวจสอบ กรุณารอให้หมดเวลาการตรวจแล้วลองใหม่','PAYMENT_VERIFICATION_IN_PROGRESS');
            $statement=$pdo->prepare("UPDATE payments SET status='rejected',transaction_ref=NULL,verified_at=NULL,rejection_reason=?,verification_lease_until=NULL,verification_token=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $statement->execute([mb_substr('ปิดโดยผู้ดูแล: '.$reason,0,500),$paymentId]);
            if($statement->rowCount()!==1)throw new HttpException(409,'สถานะรายการชำระเปลี่ยนแปลงแล้ว','PAYMENT_CHANGED');
            return $this->get($pdo,$paymentId);
        });
    }

    /** @return array<string,mixed> */
    private function claimRetry(int $paymentId,string $token): array
    {
        $lookup=$this->app->database()->pdo()->prepare('SELECT bill_id FROM payments WHERE id=?');
        $lookup->execute([$paymentId]);$reference=$lookup->fetch();
        if(!$reference)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');

        return $this->app->database()->transaction(function(PDO $pdo)use($paymentId,$reference,$token):array{
            $billLock=$pdo->prepare('SELECT id,status,total_amount,created_at FROM bills WHERE id=? FOR UPDATE');
            $billLock->execute([$reference['bill_id']]);$bill=$billLock->fetch();
            if(!$bill)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            if($bill['status']!=='pending')throw new HttpException(409,'บิลนี้ชำระแล้ว','BILL_ALREADY_PAID');
            $paymentLock=$pdo->prepare("SELECT id,bill_id,status,amount,slip_path,slip_mime,slip_hmac,verification_attempts,(verification_lease_until IS NOT NULL AND verification_lease_until>UTC_TIMESTAMP()) AS verifying FROM payments WHERE id=? AND bill_id=? FOR UPDATE");
            $paymentLock->execute([$paymentId,$reference['bill_id']]);$payment=$paymentLock->fetch();
            if(!$payment)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');
            if($payment['status']!=='pending')throw new HttpException(409,'รายการนี้มีผลตรวจสุดท้ายแล้ว','PAYMENT_NOT_PENDING',['status'=>$payment['status']]);
            if((bool)$payment['verifying'])throw new HttpException(409,'รายการกำลังตรวจสอบอยู่','PAYMENT_VERIFICATION_IN_PROGRESS');
            if((int)$payment['verification_attempts']>=20)throw new HttpException(409,'ตรวจซ้ำครบจำนวนสูงสุดแล้ว กรุณาปิดรายการและให้ผู้พักส่งสลิปใหม่','PAYMENT_RETRY_LIMIT');
            $claim=$pdo->prepare("UPDATE payments SET verification_lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),verification_token=?,verification_attempts=verification_attempts+1,rejection_reason='กำลังตรวจสอบซ้ำกับผู้ให้บริการ',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $claim->execute([$token,$paymentId]);
            if($claim->rowCount()!==1)throw new HttpException(409,'สถานะรายการชำระเปลี่ยนแปลงแล้ว','PAYMENT_CHANGED');
            return [
                'bill_id'=>(int)$payment['bill_id'],
                'amount'=>$payment['amount'],
                'slip_path'=>$payment['slip_path'],
                'slip_mime'=>$payment['slip_mime'],
                'slip_hmac'=>$payment['slip_hmac'],
                'bill_created_at'=>$bill['created_at'],
            ];
        });
    }

    private function releaseClaim(int $paymentId,string $token): void
    {
        $statement=$this->app->database()->pdo()->prepare("UPDATE payments SET verification_lease_until=NULL,verification_token=NULL,rejection_reason='ตรวจซ้ำไม่สำเร็จ กรุณาลองใหม่',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending' AND verification_token=?");
        $statement->execute([$paymentId,$token]);
    }

    /** @return array{body:string,mime:string,filename:string} */
    public function evidence(int $paymentId): array
    {
        $statement=$this->app->database()->pdo()->prepare('SELECT slip_path,slip_mime,slip_hmac FROM payments WHERE id=?');
        $statement->execute([$paymentId]);$payment=$statement->fetch();
        if(!$payment)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');
        $mime=(string)$payment['slip_mime'];
        $absolute=$this->storedSlipAbsolute((string)$payment['slip_path'],$mime,(string)$payment['slip_hmac']);
        $body=file_get_contents($absolute);
        if(!is_string($body))throw new HttpException(409,'ไม่สามารถอ่านไฟล์สลิปได้','SLIP_FILE_UNREADABLE');
        $extension=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
        return ['body'=>$body,'mime'=>$mime,'filename'=>'payment-'.$paymentId.'.'.$extension];
    }

    private function storedSlipAbsolute(string $relative,string $expectedMime,string $expectedHmac): string
    {
        $relative=str_replace('\\','/',trim($relative));
        if(!preg_match('#^storage/private/slips/[0-9]{4}/[0-9]{2}/[A-Za-z0-9][A-Za-z0-9._-]{0,255}$#D',$relative)){
            throw new HttpException(409,'ตำแหน่งไฟล์สลิปไม่ถูกต้อง กรุณาปิดรายการและให้ผู้พักส่งใหม่','SLIP_FILE_INVALID');
        }
        $root=realpath($this->app->config->root.'/storage/private/slips');
        $absolute=realpath($this->app->config->root.'/'.$relative);
        if($root===false||$absolute===false||!is_file($absolute)||!str_starts_with($absolute,$root.DIRECTORY_SEPARATOR)){
            throw new HttpException(409,'ไม่พบไฟล์สลิปเดิม กรุณาปิดรายการและให้ผู้พักส่งใหม่','SLIP_FILE_MISSING');
        }
        $allowed=['image/jpeg','image/png','image/webp'];
        $size=filesize($absolute);
        $actualMime=(string)(new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
        $image=@getimagesize($absolute);
        $actualHmac=hash_hmac_file('sha256',$absolute,$this->app->config->appKey());
        if(!in_array($expectedMime,$allowed,true)||$actualMime!==$expectedMime||$size===false||$size<=0||$size>4*1024*1024
            ||!$image||$image[0]>self::MAX_IMAGE_DIMENSION||$image[1]>self::MAX_IMAGE_DIMENSION||($image[0]*$image[1])>self::MAX_IMAGE_PIXELS
            ||!is_string($actualHmac)||!preg_match('/^[0-9a-f]{64}$/D',$expectedHmac)||!hash_equals($expectedHmac,$actualHmac)){
            throw new HttpException(409,'ไฟล์สลิปเดิมไม่ผ่านการตรวจความถูกต้อง กรุณาปิดรายการและให้ผู้พักส่งใหม่','SLIP_FILE_INTEGRITY_FAILED');
        }
        return $absolute;
    }

    /** @param array<string,mixed> $bill @return array<string,mixed> */
    private function reserve(array $bill,int $residentId,string $relative,string $mime,string $hmac,string $token): array
    {
        try{return $this->app->database()->transaction(function(PDO $pdo)use($bill,$residentId,$relative,$mime,$hmac,$token):array{
            $lock=$pdo->prepare('SELECT id,resident_id,status,total_amount FROM bills WHERE id=? FOR UPDATE');$lock->execute([$bill['id']]);$current=$lock->fetch();
            if(!$current||(int)$current['resident_id']!==$residentId)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            if($current['status']!=='pending')throw new HttpException(409,'บิลนี้ชำระแล้ว','BILL_ALREADY_PAID');
            $active=$pdo->prepare("SELECT id,status FROM payments WHERE bill_id=? AND status IN ('pending','verified') LIMIT 1 FOR UPDATE");$active->execute([$bill['id']]);
            if($row=$active->fetch())throw new HttpException(409,'บิลนี้มีสลิปที่กำลังตรวจอยู่แล้ว','PAYMENT_ALREADY_PENDING',['payment_id'=>(int)$row['id']]);
            $duplicate=$pdo->prepare('SELECT id FROM payments WHERE slip_hmac=? LIMIT 1 FOR UPDATE');$duplicate->execute([$hmac]);
            if($row=$duplicate->fetch())throw new HttpException(409,'สลิปนี้เคยถูกส่งแล้ว','DUPLICATE_SLIP',['payment_id'=>(int)$row['id']]);
            $provider=strtolower(trim((string)$this->app->settings()->value('slip_provider','')));
            $insert=$pdo->prepare("INSERT INTO payments (bill_id,resident_id,amount,status,slip_path,slip_mime,slip_hmac,provider,rejection_reason,verification_lease_until,verification_token,verification_attempts,created_at,updated_at) VALUES (?,?,?,'pending',?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $insert->execute([$bill['id'],$residentId,$current['total_amount'],$relative,$mime,$hmac,$provider,'กำลังตรวจสอบกับผู้ให้บริการ',$token]);
            return $this->get($pdo,(int)$pdo->lastInsertId());
        });}catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1062||(string)$e->getCode()==='23000')throw new HttpException(409,'สลิปหรือบิลนี้มีรายการตรวจอยู่แล้ว','DUPLICATE_PAYMENT');throw $e;}
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private function finalizeReserved(int $paymentId,int $billId,string $token,array $v,?callable $afterFinalize=null): array
    {
        return $this->app->database()->transaction(function(PDO $pdo)use($paymentId,$billId,$token,$v,$afterFinalize):array{
            $billLock=$pdo->prepare('SELECT id,status FROM bills WHERE id=? FOR UPDATE');$billLock->execute([$billId]);$bill=$billLock->fetch();if(!$bill)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            $paymentLock=$pdo->prepare('SELECT id,bill_id,status,verification_token FROM payments WHERE id=? FOR UPDATE');$paymentLock->execute([$paymentId]);$row=$paymentLock->fetch();
            if(!$row||(int)$row['bill_id']!==$billId)throw new HttpException(409,'รายการชำระเปลี่ยนแปลงแล้ว','PAYMENT_CHANGED');
            if($row['status']!=='pending'||!is_string($row['verification_token'])||!hash_equals($row['verification_token'],$token)){
                return $this->get($pdo,$paymentId);
            }
            if($bill['status']!=='pending')throw new HttpException(409,'บิลนี้ชำระแล้ว','BILL_ALREADY_PAID');
            $this->applyVerification($pdo,$paymentId,$billId,$v);
            $data=$this->get($pdo,$paymentId);if($afterFinalize!==null)$afterFinalize($data);return $data;
        });
    }

    /** @param array<string,mixed> $v */
    private function applyVerification(PDO $pdo,int $paymentId,int $billId,array $v): void
    {
        $status=(string)($v['decision']??'pending');if(!in_array($status,['pending','verified','rejected'],true))throw new \RuntimeException('Invalid slip verification decision');
        $reason=$status==='verified'?null:mb_substr((string)($v['reason']??'รอตรวจสอบซ้ำ'),0,500);
        $payload=json_encode($v['payload']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        try{
            $statement=$pdo->prepare("UPDATE payments SET status=?,provider=?,transaction_ref=?,receiver_ref=?,provider_payload=?,rejection_reason=?,verified_at=".($status==='verified'?'UTC_TIMESTAMP()':'NULL').",verification_lease_until=NULL,verification_token=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $statement->execute([$status,$v['provider']??null,$v['transaction_ref']??null,$v['receiver_ref']??null,$payload,$reason,$paymentId]);
            if($statement->rowCount()!==1)throw new HttpException(409,'สถานะรายการชำระเปลี่ยนแปลงแล้ว','PAYMENT_CHANGED');
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)===1062||(string)$e->getCode()==='23000'){
                $duplicate=$pdo->prepare("UPDATE payments SET status='rejected',provider=?,transaction_ref=NULL,receiver_ref=?,provider_payload=?,rejection_reason='เลขอ้างอิงธุรกรรมนี้ถูกใช้กับบิลอื่นแล้ว',verified_at=NULL,verification_lease_until=NULL,verification_token=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
                $duplicate->execute([$v['provider']??null,$v['receiver_ref']??null,$payload,$paymentId]);
                if($duplicate->rowCount()!==1)throw new HttpException(409,'สถานะรายการชำระเปลี่ยนแปลงแล้ว','PAYMENT_CHANGED');
                return;
            }
            throw $e;
        }
        if($status==='verified'){
            $bill=$pdo->prepare("UPDATE bills SET status='paid',paid_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $bill->execute([$billId]);
            if($bill->rowCount()!==1)throw new HttpException(409,'สถานะบิลเปลี่ยนแปลงแล้ว','BILL_CHANGED');
        }
    }

    /** @return array<string,mixed> */
    private function bill(int $billId,int $residentId): array{$statement=$this->app->database()->pdo()->prepare('SELECT id,status,total_amount,created_at FROM bills WHERE id=? AND resident_id=?');$statement->execute([$billId,$residentId]);$row=$statement->fetch();if(!$row)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');$row['id']=(int)$row['id'];return $row;}

    /** @param array<string,mixed> $file @return array{string,string,string,string} */
    private function store(array $file,int $residentId,int $billId): array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null))throw new HttpException(422,'กรุณาเลือกไฟล์สลิปที่ถูกต้อง','SLIP_UPLOAD_ERROR');
        $max=max(1024,min(4*1024*1024,$this->app->settings()->intValue('slip_max_bytes',4*1024*1024)));$size=(int)($file['size']??0);if($size<=0||$size>$max)throw new HttpException(413,'ไฟล์สลิปมีขนาดเกินค่าที่ระบบกำหนด','SLIP_TOO_LARGE');
        $tmp=$file['tmp_name'];if(!is_file($tmp)||PHP_SAPI!=='cli'&&!is_uploaded_file($tmp))throw new HttpException(422,'ไม่พบไฟล์สลิปที่อัปโหลด','SLIP_UPLOAD_ERROR');
        $actualSize=filesize($tmp);if($actualSize===false||$actualSize<=0||$actualSize>$max)throw new HttpException(413,'ไฟล์สลิปมีขนาดเกินค่าที่ระบบกำหนด','SLIP_TOO_LARGE');
        $finfo=new \finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($extensions[$mime]))throw new HttpException(422,'ไฟล์สลิปต้องเป็น JPEG, PNG หรือ WebP','SLIP_TYPE_INVALID');
        $image=@getimagesize($tmp);if(!$image||($image[0]*$image[1])>self::MAX_IMAGE_PIXELS||$image[0]>self::MAX_IMAGE_DIMENSION||$image[1]>self::MAX_IMAGE_DIMENSION)throw new HttpException(422,'รูปสลิปไม่ถูกต้องหรือมีขนาดภาพใหญ่เกินไป','SLIP_IMAGE_INVALID');
        $this->assertImageMemoryBudget((int)$image[0],(int)$image[1],(int)$actualSize);
        $directory=$this->app->config->root.'/storage/private/slips/'.gmdate('Y/m');if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new \RuntimeException('Cannot create private slip directory');
        $name=gmdate('YmdHis').'-'.$residentId.'-'.$billId.'-'.bin2hex(random_bytes(12)).'.'.$extensions[$mime];$absolute=$directory.'/'.$name;
        $decoder=['image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp'][$mime];
        $encoder=['image/jpeg'=>'imagejpeg','image/png'=>'imagepng','image/webp'=>'imagewebp'][$mime];
        if(!function_exists($decoder)||!function_exists($encoder))throw new \RuntimeException('PHP GD with JPEG, PNG, and WebP support is required');
        $resource=@$decoder($tmp);if($resource===false)throw new HttpException(422,'ระบบอ่านรูปสลิปไม่ได้ กรุณาใช้ไฟล์รูปอื่น','SLIP_IMAGE_INVALID');
        try{$written=$mime==='image/jpeg'?$encoder($resource,$absolute,90):($mime==='image/png'?$encoder($resource,$absolute,6):$encoder($resource,$absolute,90));}finally{imagedestroy($resource);}
        if(!$written||!is_file($absolute)){@unlink($absolute);throw new \RuntimeException('Cannot canonicalize uploaded slip');}
        if(filesize($absolute)===false||filesize($absolute)>$max){@unlink($absolute);throw new HttpException(413,'ไฟล์สลิปหลังตรวจรูปภาพเกินขนาดที่ตั้งไว้','SLIP_TOO_LARGE');}
        $hmac=hash_hmac_file('sha256',$absolute,$this->app->config->appKey());if(!is_string($hmac)){@unlink($absolute);throw new \RuntimeException('Cannot fingerprint slip');}
        @chmod($absolute,0600);
        $relative=substr($absolute,strlen($this->app->config->root)+1);return [$absolute,str_replace('\\','/',$relative),$mime,$hmac];
    }

    private function assertImageMemoryBudget(int $width,int $height,int $fileSize): void
    {
        // GD can hold multiple uncompressed surfaces while decoding and
        // canonicalizing. Reject before decode when the configured PHP memory
        // budget cannot safely hold the worst-case working set.
        $estimated=$width*$height*6+$fileSize*3+16*1024*1024;
        $limit=self::iniBytes((string)ini_get('memory_limit'));
        if($limit>0&&memory_get_usage(true)+$estimated>(int)floor($limit*0.85)){
            throw new HttpException(422,'รูปสลิปใช้หน่วยความจำมากเกินขอบเขตที่ปลอดภัย กรุณาลดขนาดรูป','SLIP_IMAGE_MEMORY_LIMIT');
        }
    }

    private static function iniBytes(string $value): int
    {
        $value=strtolower(trim($value));if($value===''||$value==='-1')return 0;
        if(!preg_match('/^(\d+)([kmgt]?)$/D',$value,$match))return 0;
        $bytes=(int)$match[1];$powers=[''=>0,'k'=>1,'m'=>2,'g'=>3,'t'=>4];
        for($i=0;$i<$powers[$match[2]];$i++){
            if($bytes>intdiv(PHP_INT_MAX,1024))return PHP_INT_MAX;
            $bytes*=1024;
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function get(PDO $pdo,int $id): array{$statement=$pdo->prepare('SELECT id,bill_id,resident_id,amount,status,provider,transaction_ref,receiver_ref,rejection_reason,created_at,updated_at,verified_at FROM payments WHERE id=?');$statement->execute([$id]);$row=$statement->fetch();foreach(['id','bill_id','resident_id']as$key)$row[$key]=(int)$row[$key];return $row;}
}
