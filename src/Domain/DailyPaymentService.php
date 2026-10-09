<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Integration\SlipVerifier;
use Dormitory\Support\MySqlError;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

/** Full payment before occupancy, with money and room confirmation kept separate. */
final class DailyPaymentService
{
    private readonly SlipVerifier $verifier;
    private readonly PaymentEvidenceRegistry $registry;
    public function __construct(private readonly Application $app,?\Closure $transport=null)
    {
        $this->verifier=new SlipVerifier($app,$transport);$this->registry=new PaymentEvidenceRegistry($app);
    }

    public function status(int $bookingId,string $accessToken): array
    {
        $this->ready();$booking=$this->app->dailyBookings()->validateGuestAccess($bookingId,$accessToken);
        return $this->summary($booking);
    }

    public function statusForOwner(int $bookingId): array
    {
        $this->ready();return $this->summary($this->booking($this->app->database()->pdo(),$bookingId));
    }

    public function reserve(int $bookingId,string $accessToken): array
    {
        $this->ready();$this->app->dailyBookings()->validateGuestAccess($bookingId,$accessToken);
        return $this->reserveAuthorized($bookingId);
    }

    public function reserveForOwner(int $bookingId,int $ownerId): array
    {
        $this->ready();$this->owner($this->app->database()->pdo(),$ownerId);return $this->reserveAuthorized($bookingId);
    }

    private function reserveAuthorized(int $bookingId): array
    {
        if(($this->app->settings()->paymentCapabilities()['slip_verification_ready']??false)!==true)throw new HttpException(503,'กรุณาตั้งระบบตรวจสลิปให้พร้อมก่อนรับโอนค่าจองรายวัน หรือให้เจ้าของรับเงินสด','SLIP_NOT_CONFIGURED');
        return $this->app->database()->transaction(function(PDO $pdo)use($bookingId):array{
            $booking=$this->lockedBooking($pdo,$bookingId);
            if($booking['status']!=='pending'||!(bool)$booking['hold_live'])throw new HttpException(409,'การจองหมดเวลาหรือยืนยันแล้ว กรุณาตรวจสถานะก่อนโอน','DAILY_BOOKING_NOT_PAYABLE');
            $q=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status IN('pending','verified','closed') LIMIT 1 FOR UPDATE");$q->execute([$bookingId]);
            if($q->fetchColumn()!==false)throw new HttpException(409,'มีรายการชำระแล้ว กรุณารอผล ห้ามโอนซ้ำ','PAYMENT_ALREADY_PENDING');
            $settings=$pdo->query('SELECT promptpay_target,promptpay_name FROM integration_settings WHERE id=1 FOR SHARE')->fetch();
            $target=trim((string)($settings['promptpay_target']??''));
            if($target==='')throw new HttpException(503,'ยังไม่ได้ตั้งบัญชีพร้อมเพย์ กรุณาติดต่อเจ้าของ','PROMPTPAY_NOT_CONFIGURED');
            $q=$pdo->prepare('SELECT * FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$bookingId]);$instruction=$q->fetch();
            if($instruction){$this->assertTarget($instruction,$target);return $this->envelope($instruction);}
            $base=Validator::scaledDecimal($booking['total_amount'],'total_amount',2,12);PromptPayService::payload($target,Validator::decimalString($base));
            if($base>99_999_999_999_999-99)throw new HttpException(422,'ยอดชำระเกินขอบเขต','TRANSFER_AMOUNT_INVALID');
            $this->app->limiter()->lockBucket('transfer-allocation','global');
            // Quarantine old allocations for seven days. Evidence always stays scoped by booking token.
            $this->registry->reclaimAmounts($pdo,Validator::decimalString($base+1),Validator::decimalString($base+99));
            $used=[];
            foreach(['SELECT active_amount FROM payment_amount_registry WHERE active_amount BETWEEN ? AND ? FOR UPDATE',"SELECT transfer_amount FROM transfer_instructions WHERE status IN('reserved','settled') AND transfer_amount BETWEEN ? AND ?","SELECT total_amount FROM bills WHERE status='pending' AND total_amount BETWEEN ? AND ?"]as$sql){
                $q=$pdo->prepare($sql);$q->execute([Validator::decimalString($base+1),Validator::decimalString($base+99)]);
                foreach($q->fetchAll(PDO::FETCH_COLUMN)as$v)$used[]=Validator::scaledDecimal($v,'reserved_amount',2,12);
            }
            $free=array_values(array_filter(range(1,99),static fn(int $delta):bool=>!in_array($base+$delta,$used,true)));
            if(!$free)throw new HttpException(409,'ยอดชำระช่วงนี้ถูกกันไว้ครบ 99 ยอด กรุณาตรวจรายการเดิม รอพ้นช่วงกันยอด 7 วัน หรือติดต่อเจ้าของเพื่อชำระเงินสด ห้ามสุ่มยอดหรือใช้ QR เก่า','TRANSFER_SLOTS_FULL');
            $delta=$free[random_int(0,count($free)-1)];$amount=Validator::decimalString($base+$delta);
            $q=$pdo->prepare("INSERT INTO payment_amount_registry(subject_type,subject_id,transfer_amount) VALUES('daily',?,?)");$q->execute([$bookingId,$amount]);
            $q=$pdo->prepare('INSERT INTO daily_transfer_instructions(booking_id,booking_amount,adjustment_amount,transfer_amount,promptpay_target,recipient_name) VALUES(?,?,?,?,?,?)');
            $q->execute([$bookingId,$booking['total_amount'],Validator::decimalString($delta),$amount,$target,$settings['promptpay_name']??null]);
            $q=$pdo->prepare('SELECT * FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$bookingId]);return $this->envelope($q->fetch());
        });
    }

    /** A missing record is never evidence that an earlier network request cannot still arrive. */
    public function ownerRequestStatus(int $bookingId,string $action,string $key,int $ownerId): array
    {
        $this->ready();$this->owner($this->app->database()->pdo(),$ownerId);$this->booking($this->app->database()->pdo(),$bookingId);
        $key=$this->key(['idempotency_key'=>$key]);Validator::enum($action,'action',['cash','refund','deposit-settlement','close']);$pdo=$this->app->database()->pdo();
        $query=match($action){
            'cash'=>'SELECT id FROM daily_payments WHERE booking_id=? AND request_key=?',
            'refund'=>'SELECT * FROM daily_refunds WHERE booking_id=? AND request_key=?',
            'deposit-settlement'=>'SELECT * FROM daily_deposit_settlements WHERE booking_id=? AND request_key=?',
            'close'=>'SELECT a.* FROM daily_payment_actions a JOIN daily_payments p ON p.id=a.payment_id WHERE p.booking_id=? AND a.idempotency_key=?',
        };
        $statement=$pdo->prepare($query);$statement->execute([$bookingId,$key]);$record=$statement->fetch();
        if(!$record)return ['found'=>false,'definitive_failure'=>false,'booking_id'=>$bookingId,'action'=>$action,'message'=>'ยังไม่พบผลของคำขอนี้ คำขอเดิมอาจยังประมวลผลอยู่ ห้ามรับหรือคืนเงินซ้ำ'];
        if($action==='cash')$result=$this->paymentResult($this->rawPayment($pdo,(int)$record['id']));
        elseif($action==='close')$result=$this->paymentResult($this->rawPayment($pdo,(int)$record['payment_id']));
        else{$result=$record;foreach(['id','booking_id','payment_id','recorded_by']as$field)if(isset($result[$field]))$result[$field]=(int)$result[$field];}
        return ['found'=>true,'definitive_failure'=>false,'booking_id'=>$bookingId,'action'=>$action,'result'=>$result];
    }

    public function upload(int $bookingId,string $accessToken,array $file,?callable $afterFinalize=null): array
    {
        $this->ready();$this->app->dailyBookings()->validateGuestAccess($bookingId,$accessToken);
        return $this->uploadAuthorized($bookingId,$file,$afterFinalize,false);
    }

    /** Owner recovery for a guest who lost their private link; never issues a guest token. */
    public function uploadForOwner(int $bookingId,array $file,int $ownerId,?callable $afterFinalize=null): array
    {
        $this->ready();$this->owner($this->app->database()->pdo(),$ownerId);$this->booking($this->app->database()->pdo(),$bookingId);
        return $this->uploadAuthorized($bookingId,$file,$afterFinalize,true);
    }

    private function uploadAuthorized(int $bookingId,array $file,?callable $afterFinalize,bool $ownerRecovery): array
    {
        if(($this->app->settings()->paymentCapabilities()['slip_verification_ready']??false)!==true)throw new HttpException(503,'ระบบตรวจสลิปยังไม่พร้อม กรุณาติดต่อเจ้าของ','SLIP_NOT_CONFIGURED');
        [$absolute,$relative,$mime,$hmac]=$this->app->payments()->storeEvidence($file,$bookingId,$bookingId);$token=bin2hex(random_bytes(32));
        try{
            $record=$this->app->database()->transaction(function(PDO $pdo)use($bookingId,$relative,$mime,$hmac,$token,$ownerRecovery):array{
                $booking=$this->lockedBooking($pdo,$bookingId);
                $q=$pdo->prepare("SELECT *,verification_lease_until>UTC_TIMESTAMP(6) AS verifying FROM daily_payments WHERE booking_id=? AND slip_hmac=? ORDER BY (status IN('pending','verified','closed')) DESC,id DESC LIMIT 1 FOR UPDATE");$q->execute([$bookingId,$hmac]);$old=$q->fetch();
                if($old){$old['idempotent_replay']=true;return $old;}
                $q=$pdo->prepare("SELECT id FROM daily_payments WHERE slip_hmac=? AND status IN('pending','verified','closed') LIMIT 1 FOR UPDATE");$q->execute([$hmac]);if($q->fetchColumn()!==false)throw new HttpException(409,'สลิปนี้อยู่ระหว่างตรวจหรือยืนยันกับการจองอื่นแล้ว','DUPLICATE_SLIP');
                if(!$ownerRecovery){$closed=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status='closed' LIMIT 1");$closed->execute([$bookingId]);if($closed->fetchColumn()!==false)throw new HttpException(409,'มีหลักฐานเดิมปิดไว้รอตรวจเพิ่มเติม กรุณาให้เจ้าของกู้หรือตรวจหลักฐานเดิม ห้ามโอนซ้ำ','DAILY_PAYMENT_REVIEW_REQUIRED');}
                $q=$pdo->prepare('SELECT * FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$bookingId]);$instruction=$q->fetch();
                if(!$instruction)throw new HttpException(409,'กรุณาสร้างยอดชำระของการจองนี้ก่อนส่งสลิป','DAILY_TRANSFER_REQUIRED');
                // Late evidence is accepted for a receipt/refund; it cannot restore an expired room hold.
                $q=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status IN('pending','verified') LIMIT 1 FOR UPDATE");$q->execute([$bookingId]);
                if($q->fetchColumn()!==false)throw new HttpException(409,'มีรายการชำระแล้ว กรุณาตรวจรายการเดิม ห้ามโอนซ้ำ','PAYMENT_ALREADY_PENDING');
                $q=$pdo->prepare("INSERT INTO daily_payments(booking_id,amount,transfer_amount,method,status,slip_path,slip_mime,slip_hmac,verification_lease_until,verification_token,verification_attempts,rejection_reason) VALUES(?,?,?,'slip','pending',?,?,?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND),?,1,'กำลังตรวจสอบสลิป')");
                $q->execute([$bookingId,$booking['total_amount'],$instruction['transfer_amount'],$relative,$mime,$hmac,$token]);$paymentId=(int)$pdo->lastInsertId();
                $this->registry->claimSlip($pdo,'daily',$paymentId,$hmac);return $this->rawPayment($pdo,$paymentId);
            });
        }catch(\Throwable $e){if(is_file($absolute))@unlink($absolute);throw $e;}
        if(($record['idempotent_replay']??false)===true){@unlink($absolute);$result=$this->paymentResult($record);$result['idempotent_replay']=true;return $result;}
        try{return $this->finalize((int)$record['id'],$token,$this->verify($record,$absolute),$afterFinalize);}
        catch(\Throwable $e){$this->releaseClaim((int)$record['id'],$token);throw $e;}
    }

    public function retry(int $paymentId,?callable $afterFinalize=null): array
    {
        $this->ready();
        if(($this->app->settings()->paymentCapabilities()['slip_verification_ready']??false)!==true)throw new HttpException(503,'ระบบตรวจสลิปยังไม่พร้อม','SLIP_NOT_CONFIGURED');
        $initial=$this->rawPayment($this->app->database()->pdo(),$paymentId);$token=bin2hex(random_bytes(32));
        $record=$this->app->database()->transaction(function(PDO $pdo)use($initial,$paymentId,$token):array{
            $this->lockedBooking($pdo,(int)$initial['booking_id']);$record=$this->rawPayment($pdo,$paymentId,true);
            if(!in_array($record['status'],['pending','closed'],true)||$record['method']!=='slip')throw new HttpException(409,'รายการนี้มีผลตรวจสุดท้ายแล้ว','PAYMENT_NOT_PENDING');
            if((bool)$record['verifying'])throw new HttpException(409,'รายการกำลังตรวจสอบ','PAYMENT_VERIFICATION_IN_PROGRESS');
            if($record['status']==='closed'){
                $active=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status IN('pending','verified') AND id<>? LIMIT 1 FOR UPDATE");$active->execute([$initial['booking_id'],$paymentId]);
                if($active->fetchColumn()!==false)throw new HttpException(409,'มีรายการชำระใหม่ที่รอตรวจหรือยืนยันแล้ว กรุณาตรวจเทียบหลักฐานเดิมกับรายการนั้น ห้ามโอนซ้ำ','PAYMENT_ALREADY_PENDING');
            }
            // Provider outages must not make evidence permanently unpayable after a lifetime cap.
            try{$this->app->limiter()->hit('daily-payment-retry-evidence',(string)$paymentId,20,86400);}
            catch(HttpException $e){
                if($e->errorCode!=='RATE_LIMITED')throw $e;$hours=max(1,(int)ceil((int)($e->details['retry_after']??86400)/3600));
                throw new HttpException(429,'ตรวจสลิปนี้ซ้ำครบโควตา 20 ครั้งต่อ 24 ชั่วโมงแล้ว ลองใหม่ได้หลัง '.$hours.' ชั่วโมง เก็บหลักฐานเดิมไว้แล้ว ห้ามโอนซ้ำ','RATE_LIMITED',$e->details);
            }
            $q=$pdo->prepare("UPDATE daily_payments SET status='pending',verification_token=?,verification_lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND),verification_attempts=verification_attempts+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?");$q->execute([$token,$paymentId]);return $this->rawPayment($pdo,$paymentId);
        });
        try{$path=$this->app->payments()->evidencePath($record);return $this->finalize($paymentId,$token,$this->verify($record,$path),$afterFinalize);}
        catch(\Throwable $e){$this->releaseClaim($paymentId,$token);throw $e;}
    }

    public function closePending(int $paymentId,array $input,int $ownerId,?callable $afterFinalize=null): array
    {
        $this->ready();Validator::only($input,['expected_version','idempotency_key','reason']);$key=$this->key($input);$version=Validator::id($input['expected_version']??null,'expected_version');$reason=Validator::string($input['reason']??null,'reason',3,450);
        $hash=hash('sha256',json_encode(['payment_id'=>$paymentId,'version'=>$version,'reason'=>$reason,'owner_id'=>$ownerId],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $initial=$this->rawPayment($this->app->database()->pdo(),$paymentId);
        return $this->app->database()->transaction(function(PDO $pdo)use($initial,$paymentId,$input,$ownerId,$key,$hash,$reason,$afterFinalize):array{
            $booking=$this->lockedBooking($pdo,(int)$initial['booking_id']);$this->owner($pdo,$ownerId);$record=$this->rawPayment($pdo,$paymentId,true);
            $prior=$pdo->prepare('SELECT request_hash FROM daily_payment_actions WHERE payment_id=? AND idempotency_key=?');$prior->execute([$paymentId,$key]);
            if($old=$prior->fetch()){if(!hash_equals($old['request_hash'],$hash))throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับข้อมูลอื่นแล้ว','IDEMPOTENCY_CONFLICT');return $this->paymentResult($record)+['idempotent_replay'=>true];}
            $this->version($booking,$input);
            if($record['status']!=='pending'||$record['method']!=='slip')throw new HttpException(409,'ปิดไว้ตรวจเพิ่มเติมได้เฉพาะสลิปที่ยังรอตรวจ','PAYMENT_NOT_PENDING');
            if((bool)$record['verifying'])throw new HttpException(409,'กำลังตรวจสลิป กรุณารอผลหรือรอหมดเวลาการตรวจ ห้ามปิดระหว่างตรวจ','PAYMENT_VERIFICATION_IN_PROGRESS');
            $pdo->prepare("UPDATE daily_payments SET status='closed',verification_token=NULL,verification_lease_until=NULL,rejection_reason=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'")->execute([mb_substr('ปิดไว้ตรวจเพิ่มเติม: '.$reason.' ยังไม่ได้ยืนยันเงิน ห้ามโอนซ้ำ',0,500),$paymentId]);
            $result=$this->paymentResult($this->rawPayment($pdo,$paymentId));
            $pdo->prepare("INSERT INTO daily_payment_actions(payment_id,action,idempotency_key,request_hash,reason,recorded_by,response_json) VALUES(?,'close',?,?,?,?,?)")->execute([$paymentId,$key,$hash,$reason,$ownerId,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            if($afterFinalize!==null)$afterFinalize($result);return $result;
        });
    }

    public function restoreEvidence(int $paymentId,array $file,int $ownerId,?callable $afterRestore=null): array
    {
        $this->ready();$this->owner($this->app->database()->pdo(),$ownerId);$initial=$this->rawPayment($this->app->database()->pdo(),$paymentId);
        return $this->app->database()->transaction(function(PDO $pdo)use($initial,$paymentId,$file,$ownerId,$afterRestore):array{
            $this->lockedBooking($pdo,(int)$initial['booking_id']);$this->owner($pdo,$ownerId);$record=$this->rawPayment($pdo,$paymentId,true);
            if($record['method']!=='slip')throw new HttpException(409,'รายการเงินสดไม่มีไฟล์สลิปให้กู้คืน','SLIP_NOT_FOUND');
            if((bool)$record['verifying'])throw new HttpException(409,'กำลังตรวจสลิป กรุณารอผลหรือรอหมดเวลาการตรวจก่อนกู้ไฟล์','PAYMENT_VERIFICATION_IN_PROGRESS');
            $repair=$this->app->payments()->restoreStoredEvidence($record,$file,(int)$record['booking_id'],$paymentId);
            $result=$this->paymentResult($this->rawPayment($pdo,$paymentId))+$repair;
            if($afterRestore!==null)$afterRestore($result);return $result;
        });
    }

    public function evidence(int $paymentId): array
    {
        $this->ready();$record=$this->rawPayment($this->app->database()->pdo(),$paymentId);
        if($record['method']!=='slip')throw new HttpException(404,'รายการเงินสดไม่มีสลิป','SLIP_NOT_FOUND');
        $path=$this->app->payments()->evidencePath($record);$body=file_get_contents($path);
        if(!is_string($body))throw new HttpException(409,'ไม่สามารถอ่านหลักฐาน','SLIP_FILE_UNREADABLE');
        return ['body'=>$body,'mime'=>$record['slip_mime'],'filename'=>'daily-payment-'.$paymentId.'.'.['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$record['slip_mime']]];
    }

    public function cash(int $bookingId,array $input,int $ownerId,?callable $afterFinalize=null): array
    {
        $this->ready();Validator::only($input,['expected_version','idempotency_key','reference','reason']);$key=$this->key($input);$reference=Validator::string($input['reference']??null,'reference',3,191);$reason=Validator::string($input['reason']??'รับเงินสดเต็มยอด','reason',3,500);
        return $this->app->database()->transaction(function(PDO $pdo)use($bookingId,$input,$ownerId,$key,$reference,$reason,$afterFinalize):array{
            $booking=$this->lockedBooking($pdo,$bookingId);$this->owner($pdo,$ownerId);
            $q=$pdo->prepare('SELECT * FROM daily_payments WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
            if($old){$payload=json_decode((string)$old['provider_payload'],true);if((int)$old['booking_id']!==$bookingId||$old['receipt_reference']!==$reference||($payload['reason']??null)!==$reason)throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับข้อมูลอื่นแล้ว','IDEMPOTENCY_CONFLICT');$result=$this->paymentResult($old);$result['idempotent_replay']=true;return $result;}
            $this->version($booking,$input);
            if($booking['status']!=='pending'||!(bool)$booking['hold_live'])throw new HttpException(409,'การจองนี้ไม่พร้อมรับเงินสด กรุณาตรวจสถานะห้อง','DAILY_BOOKING_NOT_PAYABLE');
            $q=$pdo->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND status IN('pending','verified') LIMIT 1 FOR UPDATE");$q->execute([$bookingId]);if($q->fetchColumn()!==false)throw new HttpException(409,'มีรายการชำระแล้ว ห้ามรับเงินซ้ำ','PAYMENT_ALREADY_PENDING');
            $q=$pdo->prepare('SELECT booking_id FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$bookingId]);
            if($q->fetchColumn()!==false)throw new HttpException(409,'ออก QR โอนเงินแล้ว กรุณาตรวจการโอนเดิมก่อนรับเงินสด เพื่อป้องกันชำระซ้ำ','DAILY_TRANSFER_ALREADY_ISSUED');
            $q=$pdo->prepare("INSERT INTO daily_payments(booking_id,amount,transfer_amount,method,status,request_key,receipt_reference,provider_payload,recorded_by,verified_at) VALUES(?,?,?,'cash','verified',?,?,?,?,UTC_TIMESTAMP(6))");
            try{$q->execute([$bookingId,$booking['total_amount'],$booking['total_amount'],$key,$reference,json_encode(['reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$ownerId]);}
            catch(PDOException $e){
                if(MySqlError::isDuplicateKey($e,'uq_daily_cash_receipt'))throw new HttpException(409,'เลขใบรับเงินสดนี้ใช้แล้ว กรุณาตรวจรายการเดิมก่อนบันทึก','CASH_RECEIPT_REFERENCE_USED');
                if(MySqlError::isDuplicateKey($e,'uq_daily_payment_request'))throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับรายการอื่นแล้ว','IDEMPOTENCY_CONFLICT');throw $e;
            }$id=(int)$pdo->lastInsertId();
            if(!$this->app->dailyBookings()->confirmPaidBooking($pdo,$booking))throw new HttpException(409,'ไม่สามารถยืนยันห้องได้ กรุณาตรวจข้อมูลก่อนรับเงินสด','DAILY_CONFIRMATION_FAILED');
            $result=$this->paymentResult($this->rawPayment($pdo,$id));if($afterFinalize!==null)$afterFinalize($result);return $result;
        });
    }

    public function refund(int $bookingId,array $input,int $ownerId,?callable $afterFinalize=null): array
    {
        $this->ready();Validator::only($input,['expected_version','idempotency_key','amount','reference','reason']);$key=$this->key($input);$amount=Validator::scaledDecimal($input['amount']??null,'amount',2,12);if($amount<=0)throw new HttpException(422,'ยอดคืนเงินต้องมากกว่า 0','REFUND_AMOUNT_INVALID');$reference=Validator::string($input['reference']??null,'reference',3,191);$reason=Validator::string($input['reason']??null,'reason',3,500);
        return $this->app->database()->transaction(function(PDO $pdo)use($bookingId,$input,$ownerId,$key,$amount,$reference,$reason,$afterFinalize):array{
            $booking=$this->lockedBooking($pdo,$bookingId);$this->owner($pdo,$ownerId);
            $q=$pdo->prepare('SELECT * FROM daily_refunds WHERE request_key=?');$q->execute([$key]);$old=$q->fetch();
            if($old){if((int)$old['booking_id']!==$bookingId||Validator::scaledDecimal($old['amount'],'amount',2,12)!==$amount||$old['reference_no']!==$reference||$old['reason']!==$reason)throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับข้อมูลอื่นแล้ว','IDEMPOTENCY_CONFLICT');$old['idempotent_replay']=true;return $old;}
            $this->version($booking,$input);$summary=$this->summary($booking,true);
            if($amount>Validator::scaledDecimal($summary['refundable_amount'],'refundable_amount',2,12))throw new HttpException(409,'ยอดคืนเงินเกินยอดที่คืนได้','REFUND_EXCEEDS_RECEIVED');
            $payment=$summary['payment'];if(!$payment||$payment['status']!=='verified')throw new HttpException(409,'ยังไม่มีเงินรับที่ยืนยันแล้ว','PAYMENT_NOT_VERIFIED');
            $purpose=in_array($booking['status'],['cancelled','expired','no_show'],true)?'cancellation':'deposit';
            $q=$pdo->prepare('INSERT INTO daily_refunds(booking_id,payment_id,amount,purpose,request_key,reference_no,reason,recorded_by) VALUES(?,?,?,?,?,?,?,?)');
            try{$q->execute([$bookingId,$payment['id'],Validator::decimalString($amount),$purpose,$key,$reference,$reason,$ownerId]);}
            catch(PDOException $e){
                if(MySqlError::isDuplicateKey($e,'uq_daily_refund_reference'))throw new HttpException(409,'เลขอ้างอิงคืนเงินนี้ใช้แล้ว กรุณาตรวจรายการเดิม ห้ามคืนเงินซ้ำ','REFUND_REFERENCE_USED');
                if(MySqlError::isDuplicateKey($e,'uq_daily_refund_request'))throw new HttpException(409,'รหัสคำขอนี้ถูกใช้กับรายการอื่นแล้ว','IDEMPOTENCY_CONFLICT');throw $e;
            }
            $result=['id'=>(int)$pdo->lastInsertId(),'booking_id'=>$bookingId,'amount'=>Validator::decimalString($amount),'purpose'=>$purpose,'reference_no'=>$reference,'reason'=>$reason];if($afterFinalize!==null)$afterFinalize($result);return $result;
        });
    }

    public function settleDeposit(int $bookingId,array $input,int $ownerId,?callable $afterFinalize=null): array
    {
        $this->ready();Validator::only($input,['expected_version','idempotency_key','retained_amount','reason']);$key=$this->key($input);$amount=Validator::scaledDecimal($input['retained_amount']??null,'retained_amount',2,12);$reason=Validator::string($input['reason']??null,'reason',3,500);
        return $this->app->database()->transaction(function(PDO $pdo)use($bookingId,$input,$ownerId,$key,$amount,$reason,$afterFinalize):array{
            $booking=$this->lockedBooking($pdo,$bookingId);$this->owner($pdo,$ownerId);$q=$pdo->prepare('SELECT * FROM daily_deposit_settlements WHERE booking_id=? OR request_key=?');$q->execute([$bookingId,$key]);$old=$q->fetch();
            if($old){if((int)$old['booking_id']!==$bookingId||$old['request_key']!==$key||Validator::scaledDecimal($old['retained_amount'],'retained_amount',2,12)!==$amount||$old['reason']!==$reason)throw new HttpException(409,'บันทึกการจัดการเงินประกันแล้ว','DEPOSIT_ALREADY_SETTLED');$old['idempotent_replay']=true;return $old;}
            $this->version($booking,$input);$summary=$this->summary($booking,true);
            if(!in_array($booking['status'],['checked_in','checked_out'],true)||!$summary['paid']||$amount>Validator::scaledDecimal($summary['deposit_remaining'],'deposit_remaining',2,12))throw new HttpException(409,'การจัดการเงินประกันหรือยอดหักไม่ถูกต้อง','DEPOSIT_SETTLEMENT_INVALID');
            $q=$pdo->prepare('INSERT INTO daily_deposit_settlements(booking_id,retained_amount,request_key,reason,recorded_by) VALUES(?,?,?,?,?)');$q->execute([$bookingId,Validator::decimalString($amount),$key,$reason,$ownerId]);
            $result=['booking_id'=>$bookingId,'retained_amount'=>Validator::decimalString($amount),'reason'=>$reason];if($afterFinalize!==null)$afterFinalize($result);return $result;
        });
    }

    public function assertCheckoutReady(PDO $pdo,int $bookingId): void
    {
        $this->ready();$summary=$this->summary($this->booking($pdo,$bookingId),true);
        if(!$summary['paid'])throw new HttpException(409,'ต้องชำระค่าพักเต็มยอดก่อนเช็กเอาต์','DAILY_PAYMENT_REQUIRED');
        if(Validator::scaledDecimal($summary['deposit_remaining'],'deposit_remaining',2,12)>0)throw new HttpException(409,'กรุณาบันทึกคืนหรือหักเงินประกันให้ครบก่อนเช็กเอาต์','DAILY_DEPOSIT_UNSETTLED');
    }

    public function assertConfirmReady(PDO $pdo,int $bookingId): void
    {
        $this->ready();$summary=$this->summary($this->booking($pdo,$bookingId),true);
        if(!$summary['paid'])throw new HttpException(409,'ต้องรับเงินค่าพักและเงินประกันเต็มยอดก่อนยืนยันการจอง','DAILY_PAYMENT_REQUIRED');
    }

    private function verify(array $record,string $path): array
    {
        $pdo=$this->app->database()->pdo();$q=$pdo->prepare('SELECT * FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$record['booking_id']]);$instruction=$q->fetch();
        try{$this->assertTarget($instruction,trim((string)$this->app->settings()->value('promptpay_target','')));}catch(HttpException $e){return ['decision'=>'pending','reason'=>$e->getMessage(),'payload'=>[]];}
        return $this->verifier->verify($path,(string)$record['slip_mime'],(string)$record['transfer_amount'],(string)$instruction['created_at']);
    }

    private function finalize(int $paymentId,string $token,array $verification,?callable $afterFinalize): array
    {
        $initial=$this->rawPayment($this->app->database()->pdo(),$paymentId);
        return $this->app->database()->transaction(function(PDO $pdo)use($initial,$paymentId,$token,$verification,$afterFinalize):array{
            $booking=$this->lockedBooking($pdo,(int)$initial['booking_id']);$record=$this->rawPayment($pdo,$paymentId,true);
            if($record['status']!=='pending'||!is_string($record['verification_token'])||!hash_equals($record['verification_token'],$token))return $this->paymentResult($record);
            $decision=(string)($verification['decision']??'pending');if(!in_array($decision,['pending','verified','rejected'],true))$decision='pending';
            if($decision==='verified'){
                $pdo->query('SELECT id FROM integration_settings WHERE id=1 FOR SHARE')->fetchColumn();
                try{
                    $settings=$this->app->settings()->slipVerificationSettings();
                    if(!is_string($verification['settings_fingerprint']??null)||!hash_equals($settings['fingerprint'],$verification['settings_fingerprint']))throw new \RuntimeException('Settings changed');
                    $q=$pdo->prepare('SELECT * FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$booking['id']]);$instruction=$q->fetch();$this->assertTarget($instruction,trim((string)$this->app->settings()->value('promptpay_target','')));
                    if(Validator::scaledDecimal($verification['payload']['amount']??null,'amount',2,12)!==Validator::scaledDecimal($record['transfer_amount'],'amount',2,12))throw new \RuntimeException('Amount changed');
                }catch(\Throwable){$decision='pending';$verification=['decision'=>'pending','reason'=>'การตั้งค่ายอดหรือบัญชีรับเงินเปลี่ยน เก็บสลิปไว้ตรวจใหม่ ห้ามโอนซ้ำ','payload'=>[]];}
            }
            if($decision!=='verified'&&is_string($verification['transaction_ref']??null)&&$verification['transaction_ref']!==''){
                $verification['payload']['unverified_transaction_ref']=$verification['transaction_ref'];$verification['transaction_ref']=null;
            }
            if($decision==='verified'&&is_string($verification['transaction_ref']??null)&&$verification['transaction_ref']!==''&&!$this->registry->claimTransaction($pdo,'daily',$paymentId,$verification['transaction_ref'])){$decision='rejected';$verification['transaction_ref']=null;$verification['reason']='เลขอ้างอิงธุรกรรมนี้เคยใช้กับรายการอื่นแล้ว';}
            if($decision==='verified'&&(!is_string($verification['transaction_ref']??null)||$verification['transaction_ref']==='')){$decision='pending';$verification['reason']='ผลตรวจไม่มีเลขอ้างอิงธนาคาร เก็บหลักฐานแล้ว ห้ามโอนซ้ำ';}
            $q=$pdo->prepare("UPDATE daily_payments SET status=?,provider=?,transaction_ref=?,receiver_ref=?,provider_payload=?,rejection_reason=?,verified_at=".($decision==='verified'?'UTC_TIMESTAMP(6)':'NULL').",verification_token=NULL,verification_lease_until=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND verification_token=? AND status='pending'");
            $q->execute([$decision,$verification['provider']??null,$verification['transaction_ref']??null,$verification['receiver_ref']??null,json_encode($verification['payload']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$decision==='verified'?null:mb_substr((string)($verification['reason']??'รอตรวจสอบซ้ำ'),0,500),$paymentId,$token]);
            if($decision==='rejected')$this->registry->releaseRejected($pdo,'daily',$paymentId);
            if($decision==='verified'){
                $q=$pdo->prepare("UPDATE payment_amount_registry SET status='settled',settled_at=UTC_TIMESTAMP(6) WHERE subject_type='daily' AND subject_id=? AND status='reserved'");$q->execute([$booking['id']]);
                $this->app->dailyBookings()->confirmPaidBooking($pdo,$booking);
            }
            $result=$this->paymentResult($this->rawPayment($pdo,$paymentId));if($afterFinalize!==null)$afterFinalize($result);return $result;
        });
    }

    private function summary(array $booking,bool $lock=false): array
    {
        $booking=$this->effectiveBooking($booking);
        $pdo=$this->app->database()->pdo();$suffix=$lock?' FOR UPDATE':'';$q=$pdo->prepare("SELECT *,verification_lease_until>UTC_TIMESTAMP(6) AS verifying FROM daily_payments WHERE booking_id=? ORDER BY (status IN('pending','verified')) DESC,id DESC LIMIT 1".$suffix);$q->execute([$booking['id']]);$payment=$q->fetch();
        // Trigger-maintained receipt counters are current reads, independent of an older snapshot.
        $refunded=$payment?Validator::scaledDecimal($payment['refunded_amount'],'amount',2,12):0;$depositRefunded=$payment?Validator::scaledDecimal($payment['deposit_refunded_amount'],'amount',2,12):0;$kept=$payment?Validator::scaledDecimal($payment['deposit_retained_amount'],'amount',2,12):0;
        $paid=$payment&&$payment['status']==='verified';$received=$paid?Validator::scaledDecimal($payment['transfer_amount'],'amount',2,12):0;$deposit=Validator::scaledDecimal($booking['deposit_amount'],'amount',2,12);$remaining=max(0,$deposit-$depositRefunded-$kept);
        $terminal=in_array($booking['status'],['cancelled','expired','no_show'],true);$refundable=$paid?($terminal?max(0,$received-$refunded-$kept):(in_array($booking['status'],['checked_in','checked_out'],true)?$remaining:0)):0;
        $capabilities=$this->app->settings()->paymentCapabilities();
        $q=$pdo->prepare('SELECT booking_id FROM daily_transfer_instructions WHERE booking_id=?');$q->execute([$booking['id']]);$hasTransfer=$q->fetchColumn()!==false;
        $closed=$pdo->prepare("SELECT *,verification_lease_until>UTC_TIMESTAMP(6) AS verifying FROM daily_payments WHERE booking_id=? AND status='closed' ORDER BY id DESC");$closed->execute([$booking['id']]);$closedPayments=array_map($this->mapPayment(...),$closed->fetchAll());
        $active=$payment&&in_array($payment['status'],['pending','verified'],true);$review=$closedPayments!==[];
        $qrReady=($capabilities['promptpay_ready']??false)===true&&($capabilities['slip_verification_ready']??false)===true;
        return ['booking_id'=>(int)$booking['id'],'booking_status'=>$booking['status'],'version'=>(int)$booking['version'],'payment'=>$payment?$this->mapPayment($payment):null,'closed_payments'=>$closedPayments,'has_closed_evidence'=>$review,'has_closed_unresolved'=>$review,'paid'=>(bool)$paid,'has_transfer_instruction'=>$hasTransfer,'cash_available'=>!$hasTransfer&&!$payment&&!$review&&$booking['status']==='pending','can_generate_qr'=>$booking['status']==='pending'&&!$active&&!$review&&$qrReady,'can_upload'=>$hasTransfer&&!$active&&!$review,'can_owner_upload'=>$hasTransfer&&!$active,'refund_needed'=>$terminal&&$refundable>0,'needs_resolution'=>($terminal&&$refundable>0)||$review,'received_amount'=>Validator::decimalString($received),'refunded_amount'=>Validator::decimalString($refunded),'refundable_amount'=>Validator::decimalString($refundable),'deposit_remaining'=>Validator::decimalString($remaining),'deposit_retained_amount'=>Validator::decimalString($kept),'capabilities'=>['promptpay_ready'=>$qrReady,'slip_verification_ready'=>($capabilities['slip_verification_ready']??false)===true]];
    }

    private function paymentResult(array $payment): array
    {
        $payment=$this->rawPayment($this->app->database()->pdo(),(int)$payment['id']);$result=$this->mapPayment($payment);$booking=$this->booking($this->app->database()->pdo(),(int)$payment['booking_id']);$summary=$this->summary($booking);
        return $result+['booking_status'=>$summary['booking_status'],'version'=>(int)$booking['version'],'refund_needed'=>$summary['refund_needed'],'needs_resolution'=>$summary['needs_resolution']];
    }

    private function mapPayment(array $row): array
    {
        $mapped=[];foreach(['id','booking_id','amount','transfer_amount','method','status','provider','transaction_ref','receipt_reference','rejection_reason','created_at','verified_at','verification_attempts']as$key)$mapped[$key]=$row[$key]??null;
        $mapped['id']=(int)$row['id'];$mapped['booking_id']=(int)$row['booking_id'];$mapped['verifying']=(bool)($row['verifying']??false);$mapped['reason']=$row['rejection_reason']??null;
        $mapped['evidence_available']=false;$mapped['evidence_error']=null;
        if($row['method']==='slip'){
            try{$this->app->payments()->evidencePath($row);$mapped['evidence_available']=true;}catch(HttpException $error){$mapped['evidence_error']=$error->errorCode;}
        }
        $mapped['can_close']=$row['method']==='slip'&&$row['status']==='pending'&&!$mapped['verifying'];
        $mapped['can_restore']=$row['method']==='slip'&&!$mapped['verifying']&&!$mapped['evidence_available'];
        $mapped['can_retry']=$row['method']==='slip'&&in_array($row['status'],['pending','closed'],true)&&!$mapped['verifying']&&$mapped['evidence_available'];
        if($mapped['can_retry']&&$row['status']==='closed'){$query=$this->app->database()->pdo()->prepare("SELECT id FROM daily_payments WHERE booking_id=? AND id<>? AND status IN('pending','verified') LIMIT 1");$query->execute([$row['booking_id'],$row['id']]);$mapped['can_retry']=$query->fetchColumn()===false;}
        return $mapped;
    }

    private function envelope(array $row): array
    {
        return ['booking_id'=>(int)$row['booking_id'],'bill_amount'=>$row['booking_amount'],'adjustment_amount'=>$row['adjustment_amount'],'amount'=>$row['transfer_amount'],'target'=>$row['promptpay_target'],'name'=>$row['recipient_name'],'payload'=>PromptPayService::payload($row['promptpay_target'],$row['transfer_amount']),'amount_locked'=>true];
    }

    private function booking(PDO $pdo,int $id): array
    {
        $q=$pdo->prepare('SELECT * FROM daily_bookings WHERE id=?');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new HttpException(404,'ไม่พบการจอง','DAILY_BOOKING_NOT_FOUND');return $this->app->dailyBookings()->withEffectiveHold($pdo,$row);
    }
    private function lockedBooking(PDO $pdo,int $id): array
    {
        $booking=$this->booking($pdo,$id);$q=$pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');$q->execute([$booking['room_id']]);$q->fetchColumn();
        $q=$pdo->prepare('SELECT * FROM daily_bookings WHERE id=? FOR UPDATE');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new HttpException(404,'ไม่พบการจอง','DAILY_BOOKING_NOT_FOUND');return $this->app->dailyBookings()->withEffectiveHold($pdo,$row);
    }
    private function rawPayment(PDO $pdo,int $id,bool $lock=false): array
    {
        $q=$pdo->prepare('SELECT *,verification_lease_until>UTC_TIMESTAMP(6) AS verifying FROM daily_payments WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);$row=$q->fetch();if(!$row)throw new HttpException(404,'ไม่พบรายการชำระ','PAYMENT_NOT_FOUND');return $row;
    }
    private function releaseClaim(int $id,string $token): void
    {
        try{$q=$this->app->database()->pdo()->prepare("UPDATE daily_payments SET verification_token=NULL,verification_lease_until=NULL,rejection_reason='ตรวจสลิปไม่สำเร็จ เก็บหลักฐานแล้ว ห้ามโอนซ้ำ',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending' AND verification_token=?");$q->execute([$id,$token]);}catch(\Throwable){error_log('Unable to release daily verification claim '.$id);}
    }
    private function assertTarget(?array $instruction,string $target): void
    {
        if(!$instruction||$target===''||!hash_equals((string)$instruction['promptpay_target'],$target))throw new HttpException(409,'บัญชีรับเงินเปลี่ยน กรุณาให้เจ้าของตรวจสลิปเดิม ห้ามโอนซ้ำ','TRANSFER_TARGET_CHANGED');
    }
    private function key(array $input): string
    {
        $key=Validator::string($input['idempotency_key']??null,'idempotency_key',16,64);if(preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$key)!==1)throw new HttpException(422,'รหัสคำขอไม่ถูกต้อง','VALIDATION_ERROR');return $key;
    }
    private function version(array $booking,array $input): void
    {
        if(Validator::id($input['expected_version']??null,'expected_version')!==(int)$booking['version'])throw new HttpException(409,'ข้อมูลการจองเปลี่ยนแล้ว กรุณาโหลดใหม่','DAILY_BOOKING_CHANGED');
    }
    private function owner(PDO $pdo,int $id): void
    {
        $q=$pdo->prepare("SELECT id FROM admin_users WHERE id=? AND role='owner' AND active=1 AND retired_at IS NULL FOR SHARE");$q->execute([$id]);if($q->fetchColumn()===false)throw new HttpException(403,'ต้องเป็นเจ้าของระบบที่ใช้งานอยู่','FORBIDDEN');
    }
    private function ready(): void
    {
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('payment_evidence_registry','payment_amount_registry','daily_transfer_instructions','daily_payments','daily_refunds','daily_deposit_settlements')");
        if((int)$q->fetchColumn()!==6)throw new HttpException(503,'กรุณาติดตั้ง migration 019 ก่อนใช้การชำระรายวัน','DAILY_PAYMENT_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='daily_payment_actions'");
        if((int)$q->fetchColumn()!==1)throw new HttpException(503,'กรุณาติดตั้ง migration 020 ก่อนใช้การตรวจหลักฐานรายวัน','DAILY_PAYMENT_REVIEW_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='daily_payments' AND column_name='status'");
        if($q->fetchColumn()!=="enum('pending','verified','rejected','closed')")throw new HttpException(503,'กรุณาติดตั้ง migration 020 ให้ครบก่อนใช้การตรวจหลักฐานรายวัน','DAILY_PAYMENT_REVIEW_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='daily_payments' AND constraint_name='chk_daily_payment_review_v20' AND enforced='YES'");
        if((int)$q->fetchColumn()!==1)throw new HttpException(503,'กฎการกู้คืนหลักฐานยังติดตั้งไม่ครบ กรุณาตรวจ migration 020','DAILY_PAYMENT_REVIEW_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND non_unique=0 AND index_name IN('uq_evidence_slip','uq_evidence_transaction','uq_amount_global_active','uq_daily_payment_active')");
        if((int)$q->fetchColumn()!==4)throw new HttpException(503,'โครงสร้างป้องกันชำระซ้ำไม่ครบ กรุณาตรวจ migration 019','DAILY_PAYMENT_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT cc.check_clause AS clause,tc.enforced AS is_enforced FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_schema=tc.constraint_schema AND cc.constraint_name=tc.constraint_name WHERE tc.constraint_schema=DATABASE() AND tc.table_name='daily_payments' AND tc.constraint_name='chk_daily_finance_schema_v19'");$marker=$q->fetch();
        if(!$marker||($marker['is_enforced']??null)!=='YES'||preg_replace('/[\\s`()]/','',strtolower((string)$marker['clause']))!=='amount>0andtransfer_amount>=amount')throw new HttpException(503,'กฎป้องกันแก้ไขหลักฐานการเงินไม่ครบ กรุณาตรวจ migration 019','DAILY_PAYMENT_SCHEMA_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM payments p LEFT JOIN payment_evidence_registry e ON e.subject_type='monthly' AND e.subject_id=p.id WHERE e.subject_id IS NULL OR NOT(e.slip_hmac<=>p.slip_hmac) OR NOT(e.transaction_ref<=>p.transaction_ref)");
        if((int)$q->fetchColumn()!==0)throw new HttpException(503,'หลักฐานชำระรายเดือนยังย้ายเข้าทะเบียนกลางไม่ครบ กรุณาตรวจ migration 019','DAILY_PAYMENT_BACKFILL_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM transfer_instructions t LEFT JOIN payment_amount_registry a ON a.subject_type='monthly' AND a.subject_id=t.bill_id WHERE a.subject_id IS NULL OR a.transfer_amount<>t.transfer_amount");
        if((int)$q->fetchColumn()!==0)throw new HttpException(503,'ยอดโอนรายเดือนยังย้ายเข้าทะเบียนกลางไม่ครบ กรุณาตรวจ migration 019','DAILY_PAYMENT_BACKFILL_REQUIRED');
        $q=$this->app->database()->pdo()->query("SELECT COUNT(*) FROM daily_payments p WHERE p.refunded_amount<>COALESCE((SELECT SUM(r.amount) FROM daily_refunds r WHERE r.payment_id=p.id),0) OR p.deposit_refunded_amount<>COALESCE((SELECT SUM(r.amount) FROM daily_refunds r WHERE r.payment_id=p.id AND r.purpose='deposit'),0) OR (p.status='verified' AND p.deposit_retained_amount<>COALESCE((SELECT d.retained_amount FROM daily_deposit_settlements d WHERE d.booking_id=p.booking_id),0))");
        if((int)$q->fetchColumn()!==0)throw new HttpException(503,'ยอดคืนและหักประกันไม่ตรงกับหลักฐาน กรุณาให้เจ้าของตรวจสอบ','DAILY_PAYMENT_LEDGER_INVALID');
    }

    private function effectiveBooking(array $booking): array {if($booking['status']==='pending'&&array_key_exists('hold_expired',$booking)&&(bool)$booking['hold_expired'])$booking['status']='expired';elseif($booking['status']==='pending'&&array_key_exists('hold_live',$booking)&&!(bool)$booking['hold_live'])$booking['status']='expired';return $booking;}
}
