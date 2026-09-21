<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;
use PDO;

final class NotificationService
{
    private const LINE_ENDPOINT = 'https://api.line.me/v2/bot/message/push';
    private const CLAIM_LEASE_SECONDS = 120;

    public function __construct(private readonly Application $app,private readonly ?\Closure $pushTransport=null) {}

    /** @return array<string,mixed> */
    public function enqueueBill(int $billId): array
    {
        return $this->app->lineOfficialAccounts()->withRegistryLock(function()use($billId):array{
            return $this->app->database()->transaction(function(PDO $pdo)use($billId):array{
                $q=$pdo->prepare('SELECT resident_id FROM bills WHERE id=? FOR UPDATE');$q->execute([$billId]);$resident=$q->fetchColumn();
                if($resident===false)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
                $targets=$this->recipients((int)$resident);
                if($targets===[])throw new HttpException(422,'ผู้พักยังไม่ได้ผูก LINE ที่พร้อมรับบิล','LINE_NOT_LINKED');
                $rows=[];foreach($targets as $target)$rows[]=$this->enqueueForRecipient($billId,$target);
                $first=$rows[0];$first['deliveries']=$rows;$first['recipient_count']=count($rows);
                $first['newly_queued']=count(array_filter($rows,fn(array $r):bool=>in_array($r['enqueue_state'],['newly_queued','requeued'],true)))>0;
                if($first['newly_queued'])$first['enqueue_state']='newly_queued';
                return $first;
            });
        });
    }

    /** All currently authorized recipients; legacy OA0 remains separately pinned. */
    public function recipients(int $residentId): array
    {
        $rows=$this->app->lineRoomBindings()->recipients($residentId);
        $q=$this->app->database()->pdo()->prepare("SELECT r.line_user_id,o.id AS occupancy_id FROM residents r JOIN occupancies o ON o.resident_id=r.id AND o.status='active' WHERE r.id=? AND r.active=1 LIMIT 2");$q->execute([$residentId]);$legacy=$q->fetchAll();
        if(count($legacy)===1&&$this->isLineBindingVerified($residentId,$legacy[0]['line_user_id'])&&!$this->app->lineRoomBindings()->isBlocked($residentId)){
            $oa=$this->app->lineOfficialAccounts()->get(0);
            if(($oa['line_binding_ready']??false)===true)$rows[]=['id'=>0,'oa_id'=>0,'resident_id'=>$residentId,'occupancy_id'=>(int)$legacy[0]['occupancy_id'],'line_user_id'=>$legacy[0]['line_user_id']];
        }
        $unique=[];foreach($rows as $row)$unique[$row['oa_id'].':'.$row['line_user_id']]=$row;return array_values($unique);
    }

    private function enqueueForRecipient(int $billId,array $target): array
    {
        return $this->app->database()->transaction(function(PDO $pdo) use($billId,$target): array {
            $statement=$pdo->prepare("SELECT b.id,b.bill_no,b.period,b.due_date,b.total_amount,b.status,
                    b.room_code_snapshot AS room_code,res.id AS resident_id,res.line_user_id
                FROM bills b
                JOIN residents res ON res.id=b.resident_id
                WHERE b.id=? FOR UPDATE");
            $statement->execute([$billId]);
            $bill=$statement->fetch();
            if(!$bill)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            // Keep the lock order aligned with PaymentService: bill first,
            // then the latest payment. A current locking read prevents a
            // manual or bulk API call from queuing a duplicate reminder while
            // a slip is pending or has already been verified.
            $latestPayment=$pdo->prepare('SELECT status FROM payments WHERE bill_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $latestPayment->execute([$billId]);
            $paymentStatus=$latestPayment->fetchColumn();
            if($paymentStatus==='pending'){
                throw new HttpException(409,'บิลนี้มีสลิปที่กำลังตรวจสอบ จึงไม่ส่งข้อความให้ชำระซ้ำ','BILL_PAYMENT_PENDING',['payment_status'=>$paymentStatus]);
            }
            if($paymentStatus==='verified'){
                throw new HttpException(409,'สลิปของบิลนี้ผ่านการตรวจแล้ว จึงไม่ส่งข้อความให้ชำระซ้ำ','BILL_PAYMENT_VERIFIED',['payment_status'=>$paymentStatus]);
            }
            if($bill['status']!=='pending'){
                throw new HttpException(409,'บิลนี้ชำระแล้ว จึงไม่สามารถเข้าคิวแจ้งชำระได้','BILL_NOT_PENDING',['status'=>$bill['status']]);
            }
            $this->app->lineOfficialAccounts()->credentials((int)$target['oa_id']);
            $bill['line_user_id']=$target['line_user_id'];
            if(!$this->validLineUserId($bill['line_user_id']??null)){
                throw new HttpException(422,'ผู้พักยังไม่ได้ผูกบัญชี LINE','LINE_NOT_LINKED');
            }
            if((int)$target['resident_id']!==(int)$bill['resident_id']||((int)$target['id']===0&&!$this->isLineBindingVerified((int)$bill['resident_id'],(string)$bill['line_user_id']))){
                throw new HttpException(422,'ผู้พักต้องยืนยันบัญชี LINE ด้วยรหัสครั้งเดียวก่อนรับบิล','LINE_NOT_VERIFIED');
            }

            $payload=$this->billPayload($bill);
            $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $existingStatement=$pdo->prepare("SELECT id,status,attempts,recipient,(created_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) AS retry_expired FROM notification_outbox WHERE bill_id=? AND purpose='bill_delivery' AND line_delivery_key=? LIMIT 1 FOR UPDATE");
            $existingStatement->execute([$billId,(int)$target['id']]);
            $existing=$existingStatement->fetch();
            $enqueueState='newly_queued';

            if(!$existing){
                $insert=$pdo->prepare("INSERT INTO notification_outbox
                    (bill_id,resident_id,line_oa_id,line_binding_id,channel,purpose,recipient,payload,status,attempts,next_attempt_at,retry_key,created_at,updated_at)
                    VALUES (?,?,?,?,'line','bill_delivery',?,?,'pending',0,UTC_TIMESTAMP(),?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$billId,$bill['resident_id'],$target['oa_id'],$target['id']?:null,$bill['line_user_id'],$encoded,$this->randomUuid()]);
                $outboxId=(int)$pdo->lastInsertId();
            }else{
                $outboxId=(int)$existing['id'];
                $enqueueState='already_'.$existing['status'];
                if($existing['status']==='failed'){
                    if((int)$existing['retry_expired']===1){
                        throw new HttpException(409,'พ้นช่วงลองส่งซ้ำที่ LINE ป้องกันข้อความซ้ำได้ กรุณาตรวจประวัติการส่งก่อน ไม่สร้างคำขอใหม่อัตโนมัติ','LINE_RETRY_WINDOW_EXPIRED');
                    }
                    if(!is_string($existing['recipient'])||!hash_equals($existing['recipient'],(string)$bill['line_user_id'])){
                        throw new HttpException(409,'ผู้รับเปลี่ยนจากคำขอเดิม กรุณาตรวจการผูก LINE ก่อนส่ง','LINE_DELIVERY_IDENTITY_CHANGED');
                    }
                    // Retry the original generation: never rotate its key, recipient, body or creation time.
                    $update=$pdo->prepare("UPDATE notification_outbox SET status='pending',last_error=NULL,
                        next_attempt_at=UTC_TIMESTAMP(),claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP()
                        WHERE id=? AND status='failed'");
                    $update->execute([$outboxId]);
                    $enqueueState='requeued';
                }elseif($existing['status']==='pending'&&(int)$existing['attempts']===0){
                    $update=$pdo->prepare("UPDATE notification_outbox
                        SET resident_id=?,recipient=?,payload=?,retry_key=?,claim_token=NULL,lease_until=NULL,
                            created_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                        WHERE id=? AND status='pending' AND attempts=0");
                    $update->execute([$bill['resident_id'],$bill['line_user_id'],$encoded,$this->randomUuid(),$outboxId]);
                }
            }

            $get=$pdo->prepare('SELECT id,bill_id,status,attempts,next_attempt_at,retry_key FROM notification_outbox WHERE id=?');
            $get->execute([$outboxId]);
            $row=$get->fetch();
            if(!$row)throw new \RuntimeException('Cannot read queued LINE notification');
            foreach(['id','bill_id','attempts'] as $key)$row[$key]=(int)$row[$key];
            $row['enqueue_state']=$enqueueState;
            $row['newly_queued']=$enqueueState==='newly_queued';
            return $row;
        });
    }

    /** @return array<string,mixed> */
    public function enqueuePeriod(array $input): array
    {
        Validator::only($input,['period']);$period=Validator::periodDate($input['period']??null);
        $statement=$this->app->database()->pdo()->prepare('SELECT id FROM bills WHERE period=? ORDER BY id');$statement->execute([$period]);
        $queued=[];$already=[];$skipped=[];
        foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $id){
            try{
                $item=$this->enqueueBill((int)$id);
                if(in_array($item['enqueue_state'],['newly_queued','requeued'],true))$queued[]=$item;
                else $already[]=$item;
            }catch(HttpException $e){
                $skipped[]=['bill_id'=>(int)$id,'code'=>$e->errorCode,'message'=>$e->getMessage()];
            }
        }
        return ['period'=>substr($period,0,7),'queued'=>$queued,'already'=>$already,'skipped'=>$skipped];
    }

    /** @return array{processed:int,sent:int,failed:int,retried:int,lost_claims:int,recovered:int} */
    public function process(int $limit=25): array
    {
        $result=$this->processBills($limit);
        $remaining=max(0,$limit-$result['processed']);
        if($remaining>0){$notices=$this->app->lineNotices()->process($remaining);foreach($result as$key=>$value)$result[$key]+=$notices[$key]??0;}
        return $result;
    }

    private function processBills(int $limit=25): array
    {
        $limit=max(1,min(100,$limit));
        $result=[
            'processed'=>0,'sent'=>0,'failed'=>0,'retried'=>0,
            'lost_claims'=>0,'recovered'=>$this->recoverExpiredClaims(),
        ];
        $max=max(1,min(20,$this->app->settings()->intValue('line_max_attempts',5)));
        for($position=0;$position<$limit;$position++){
            $row=$this->claimNext();
            if($row===null)break;
            $id=(int)$row['id'];$billId=(int)$row['bill_id'];$claimToken=(string)$row['claim_token'];
            $result['processed']++;
            if((int)$row['resident_id']<1){
                if($this->markTerminalFailure($id,$claimToken,'LINE delivery data is incomplete'))$result['failed']++;
                else $result['lost_claims']++;
                continue;
            }
            try{
                $delivery=$this->app->lineOfficialAccounts()->withRegistryLock(fn()=>$this->withLineBindingLock((int)$row['resident_id'],function()use($id,$billId,$claimToken):array{
                    // Refresh only a still-live claim after the advisory binding
                    // lock. A stale worker never reaches the provider.
                    if(!$this->refreshClaim($id,$claimToken))return ['lost_claim'=>true];
                    return $this->deliverClaimed($id,$billId,$claimToken);
                }));
                if(is_string($delivery['terminal']??null)){
                    if($this->markTerminalFailure($id,$claimToken,$delivery['terminal']))$result['failed']++;
                    else $result['lost_claims']++;
                }elseif(($delivery['sent']??false)===true)$result['sent']++;
                else $result['lost_claims']++;
            }catch(LineDeliveryException $e){
                $outcome=$this->settleClaimFailure($id,$claimToken,(int)$row['attempts'],$max,$e->getMessage(),$e->retryable);
                $result[$outcome==='lost_claim'?'lost_claims':$outcome]++;
            }catch(\Throwable $e){
                // A provider acceptance followed by a DB error remains safe:
                // the immutable retry key makes the next attempt return 409.
                $outcome=$this->settleClaimFailure($id,$claimToken,(int)$row['attempts'],$max,$e->getMessage(),true);
                $result[$outcome==='lost_claim'?'lost_claims':$outcome]++;
            }
        }
        return $result;
    }

    private function recoverExpiredClaims(): int
    {
        $statement=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
            SET status='pending',next_attempt_at=UTC_TIMESTAMP(6),
                claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6)
            WHERE status='processing'
              AND (claim_token IS NULL OR lease_until IS NULL OR lease_until<=UTC_TIMESTAMP(6))");
        $statement->execute();
        return $statement->rowCount();
    }

    /** @return array{id:int,bill_id:int,resident_id:int,attempts:int,claim_token:string}|null */
    private function claimNext(): ?array
    {
        return $this->app->database()->transaction(function(PDO $pdo): ?array {
            $candidate=$pdo->query("SELECT id FROM notification_outbox
                WHERE status='pending' AND next_attempt_at<=UTC_TIMESTAMP(6)
                ORDER BY next_attempt_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetchColumn();
            if($candidate===false)return null;
            $id=(int)$candidate;$token=bin2hex(random_bytes(32));
            $claim=$pdo->prepare("UPDATE notification_outbox
                SET status='processing',attempts=attempts+1,claim_token=?,
                    lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ".self::CLAIM_LEASE_SECONDS." SECOND),
                    updated_at=UTC_TIMESTAMP(6)
                WHERE id=? AND status='pending' AND next_attempt_at<=UTC_TIMESTAMP(6)");
            $claim->execute([$token,$id]);
            if($claim->rowCount()!==1)throw new \RuntimeException('Notification claim changed while locked');
            $get=$pdo->prepare("SELECT id,bill_id,resident_id,attempts,claim_token
                FROM notification_outbox
                WHERE id=? AND status='processing' AND claim_token=?");
            $get->execute([$id,$token]);$row=$get->fetch();
            if(!$row)throw new \RuntimeException('Cannot reload claimed notification');
            return [
                'id'=>(int)$row['id'],'bill_id'=>(int)$row['bill_id'],'resident_id'=>(int)$row['resident_id'],
                'attempts'=>(int)$row['attempts'],'claim_token'=>(string)$row['claim_token'],
            ];
        });
    }

    /** @return array{sent?:true,terminal?:string,lost_claim?:true} */
    private function deliverClaimed(int $id,int $billId,string $claimToken): array
    {
        return $this->app->database()->transaction(function(PDO $pdo)use($id,$billId,$claimToken):array{
            // PaymentService always locks a bill before its payment row. Keep
            // that global order here, then lock the outbox claim last. Holding
            // the bill lock through provider acceptance makes a concurrent
            // slip reservation wait instead of creating a pending payment
            // between this final check and the LINE push.
            $billLock=$pdo->prepare('SELECT id,status,resident_id FROM bills WHERE id=? FOR UPDATE');
            $billLock->execute([$billId]);$bill=$billLock->fetch();
            if(!$bill)return ['terminal'=>'LINE delivery cancelled: bill no longer exists'];

            $latestPaymentLock=$pdo->prepare('SELECT status FROM payments WHERE bill_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $latestPaymentLock->execute([$billId]);
            $paymentStatus=$latestPaymentLock->fetchColumn();

            $claimLock=$pdo->prepare("SELECT id,resident_id,line_oa_id,line_binding_id,retry_key,attempts,recipient,payload,
                    (created_at<=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) AS retry_generation_expired
                FROM notification_outbox
                WHERE id=? AND bill_id=? AND status='processing' AND claim_token=?
                  AND lease_until>UTC_TIMESTAMP(6)
                FOR UPDATE");
            $claimLock->execute([$id,$billId,$claimToken]);$current=$claimLock->fetch();
            if(!$current)return ['lost_claim'=>true];
            if((int)$current['retry_generation_expired']===1)return ['terminal'=>'LINE retry generation expired'];
            if($bill['status']!=='pending')return ['terminal'=>'Bill is no longer pending'];
            if($paymentStatus==='pending')return ['terminal'=>'LINE delivery cancelled: payment slip is pending review'];
            if($paymentStatus==='verified')return ['terminal'=>'LINE delivery cancelled: payment slip is already verified'];
            if((int)$current['resident_id']!==(int)$bill['resident_id'])return ['terminal'=>'LINE delivery data is incomplete'];

            $resident=$pdo->prepare('SELECT line_user_id FROM residents WHERE id=?');
            $resident->execute([$bill['resident_id']]);$currentLineUserId=$resident->fetchColumn();
            $recipient=$current['recipient']??null;
            if(!$this->validLineUserId($recipient))return ['terminal'=>'Stored LINE recipient is invalid'];
            if($current['line_binding_id']!==null){
                $binding=$this->app->lineRoomBindings()->verified((int)$current['line_binding_id'],(string)$recipient,(int)$current['line_oa_id']);
                if($binding===null||(int)$binding['resident_id']!==(int)$bill['resident_id'])return ['terminal'=>'LINE binding is no longer verified'];
            }else{
                if((int)$current['line_oa_id']!==0||!$this->validLineUserId($currentLineUserId)||!hash_equals((string)$recipient,(string)$currentLineUserId))return ['terminal'=>'Verified LINE recipient changed'];
                if(!$this->isLineBindingVerified((int)$bill['resident_id'],(string)$recipient)||$this->app->lineRoomBindings()->isBlocked((int)$bill['resident_id']))return ['terminal'=>'LINE binding is no longer verified'];
            }
            if(!$this->validRetryUuid($current['retry_key']??null))return ['terminal'=>'Stored LINE retry key is invalid'];
            $body=$this->storedLinePayloadBody($current['payload']??null,(string)$recipient);
            $oa=$this->app->lineOfficialAccounts()->credentials((int)$current['line_oa_id']);
            $acceptance=$this->pushLine($body,(string)$current['retry_key'],$oa['access_token']);
            $sent=$pdo->prepare("UPDATE notification_outbox
                SET status='sent',sent_at=UTC_TIMESTAMP(6),last_error=NULL,
                    line_request_id=?,line_accepted_request_id=?,
                    claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6)
                WHERE id=? AND status='processing' AND claim_token=?");
            $sent->execute([$acceptance['request_id'],$acceptance['accepted_request_id'],$id,$claimToken]);
            return $sent->rowCount()===1?['sent'=>true]:['lost_claim'=>true];
        });
    }

    private function refreshClaim(int $id,string $claimToken): bool
    {
        $statement=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
            SET lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ".self::CLAIM_LEASE_SECONDS." SECOND),
                updated_at=UTC_TIMESTAMP(6)
            WHERE id=? AND status='processing' AND claim_token=?
              AND lease_until>UTC_TIMESTAMP(6)");
        $statement->execute([$id,$claimToken]);
        return $statement->rowCount()===1;
    }

    /** @return 'failed'|'retried'|'lost_claim' */
    private function settleClaimFailure(int $id,string $claimToken,int $attempts,int $max,string $message,bool $retryable): string
    {
        $terminal=!$retryable||$attempts>=$max;
        $delay=min(3600,30*(2**min(6,max(0,$attempts-1))));
        $safe=$this->safeWorkerMessage($message,'LINE delivery failed');
        $sql=$terminal
            ?"UPDATE notification_outbox
                SET status='failed',last_error=?,next_attempt_at=UTC_TIMESTAMP(6),
                    claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6)
                WHERE id=? AND status='processing' AND claim_token=?"
            :"UPDATE notification_outbox
                SET status='pending',last_error=?,
                    next_attempt_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$delay} SECOND),
                    claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6)
                WHERE id=? AND status='processing' AND claim_token=?";
        $statement=$this->app->database()->pdo()->prepare($sql);
        $statement->execute([$safe,$id,$claimToken]);
        if($statement->rowCount()!==1)return 'lost_claim';
        return $terminal?'failed':'retried';
    }

    /**
     * Record liveness without storing the platform's raw replica identifier.
     * @param array<string,int>|null $cycle
     */
    public function recordWorkerHeartbeat(string $instanceIdentity,string $status='running',?array $cycle=null,?string $error=null): void
    {
        $instanceIdentity=trim($instanceIdentity);
        if($instanceIdentity===''||strlen($instanceIdentity)>512)throw new \InvalidArgumentException('Invalid notification worker identity');
        if(!in_array($status,['starting','running','error','stopped'],true))throw new \InvalidArgumentException('Invalid notification worker status');
        $workerId=hash_hmac('sha256',"notification-worker\0".$instanceIdentity,$this->app->config->appKey());
        $safeError=$error!==null?$this->safeWorkerMessage($error,'Notification worker failed'):null;
        if($cycle===null){
            $statement=$this->app->database()->pdo()->prepare("INSERT INTO notification_worker_heartbeats
                (worker_id,status,started_at,heartbeat_at,last_error,created_at,updated_at)
                VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE
                    started_at=IF(VALUES(status)='starting',VALUES(started_at),started_at),
                    status=VALUES(status),heartbeat_at=VALUES(heartbeat_at),
                    last_error=VALUES(last_error),updated_at=UTC_TIMESTAMP(6)");
            $statement->execute([$workerId,$status,$safeError]);
            return;
        }
        $counts=[];
        foreach(['processed','sent','failed','retried','lost_claims','recovered']as$field){
            $value=$cycle[$field]??0;
            if(!is_int($value)||$value<0||$value>1000000)throw new \InvalidArgumentException('Invalid notification worker cycle counters');
            $counts[]=$value;
        }
        $statement=$this->app->database()->pdo()->prepare("INSERT INTO notification_worker_heartbeats
            (worker_id,status,started_at,heartbeat_at,last_cycle_at,
             last_processed,last_sent,last_failed,last_retried,last_lost_claims,last_recovered,
             last_error,created_at,updated_at)
            VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE
                started_at=IF(VALUES(status)='starting',VALUES(started_at),started_at),
                status=VALUES(status),heartbeat_at=VALUES(heartbeat_at),
                last_cycle_at=VALUES(last_cycle_at),last_processed=VALUES(last_processed),
                last_sent=VALUES(last_sent),last_failed=VALUES(last_failed),
                last_retried=VALUES(last_retried),last_lost_claims=VALUES(last_lost_claims),
                last_recovered=VALUES(last_recovered),last_error=VALUES(last_error),
                updated_at=UTC_TIMESTAMP(6)");
        $statement->execute([$workerId,$status,...$counts,$safeError]);
    }

    /** @return array<string,mixed> */
    public function workerHealth(int $heartbeatMaxAgeSeconds=600,int $queueMaxLagSeconds=900): array
    {
        $heartbeatMaxAgeSeconds=max(30,min(3600,$heartbeatMaxAgeSeconds));
        $queueMaxLagSeconds=max(30,min(86400,$queueMaxLagSeconds));
        $worker=$this->app->database()->pdo()->query("SELECT status,
                GREATEST(0,TIMESTAMPDIFF(SECOND,heartbeat_at,UTC_TIMESTAMP(6))) AS heartbeat_age_seconds,
                last_cycle_at,last_processed,last_sent,last_failed,last_retried,last_lost_claims,last_recovered
            FROM notification_worker_heartbeats ORDER BY heartbeat_at DESC LIMIT 1")->fetch();
        $queue=$this->app->database()->pdo()->query("SELECT COUNT(*) AS total,
                COALESCE(SUM(n.status='pending'),0) AS pending,
                COALESCE(SUM(n.status='processing'),0) AS processing,
                COALESCE(SUM(n.status='failed' AND b.status='pending'),0) AS failed,
                COALESCE(SUM(n.status='processing' AND (n.lease_until IS NULL OR n.lease_until<=UTC_TIMESTAMP(6))),0) AS stale_claims,
                COALESCE(MAX(CASE WHEN n.status='pending' AND n.next_attempt_at<=UTC_TIMESTAMP(6)
                    THEN GREATEST(0,TIMESTAMPDIFF(SECOND,n.next_attempt_at,UTC_TIMESTAMP(6)))
                    ELSE NULL END),0) AS oldest_due_lag_seconds
            FROM notification_outbox n
            JOIN bills b ON b.id=n.bill_id")->fetch();
        if(!$queue)throw new \RuntimeException('Cannot read notification queue health');
        $heartbeatAge=$worker!==false?(int)$worker['heartbeat_age_seconds']:null;
        $workerLive=$worker!==false&&in_array($worker['status'],['starting','running'],true)
            &&$heartbeatAge!==null&&$heartbeatAge<=$heartbeatMaxAgeSeconds;
        $queueLag=(int)$queue['oldest_due_lag_seconds'];$staleClaims=(int)$queue['stale_claims'];
        $attention=$staleClaims>0||(int)$queue['failed']>0||(int)($worker['last_lost_claims']??0)>0;
        $healthy=$workerLive&&$queueLag<=$queueMaxLagSeconds&&$staleClaims===0;
        return [
            'status'=>$healthy?($attention?'degraded':'ok'):'unhealthy',
            'worker_live'=>$workerLive,
            'worker_status'=>$worker!==false?(string)$worker['status']:'missing',
            'heartbeat_age_seconds'=>$heartbeatAge,
            'last_cycle_at'=>$worker!==false?$worker['last_cycle_at']:null,
            'last_processed'=>$worker!==false?(int)$worker['last_processed']:0,
            'last_sent'=>$worker!==false?(int)$worker['last_sent']:0,
            'last_failed'=>$worker!==false?(int)$worker['last_failed']:0,
            'last_retried'=>$worker!==false?(int)$worker['last_retried']:0,
            'last_lost_claims'=>$worker!==false?(int)$worker['last_lost_claims']:0,
            'last_recovered'=>$worker!==false?(int)$worker['last_recovered']:0,
            'queue'=>[
                'total'=>(int)$queue['total'],'pending'=>(int)$queue['pending'],
                'processing'=>(int)$queue['processing'],'failed'=>(int)$queue['failed'],
                'stale_claims'=>$staleClaims,'oldest_due_lag_seconds'=>$queueLag,
            ],
            'attention_required'=>$attention,
        ];
    }

    private function safeWorkerMessage(string $message,string $fallback): string
    {
        $safe=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$message)??$fallback);
        return substr($safe!==''?$safe:$fallback,0,1000);
    }

    /** @param array<string,mixed> $bill @return array<string,mixed> */
    private function billPayload(array $bill): array
    {
        $text="ใบแจ้งหนี้ {$bill['bill_no']}\nห้อง {$bill['room_code']}\nรอบบิล ".substr((string)$bill['period'],0,7)."\nยอดบิล {$bill['total_amount']} บาท\nครบกำหนด {$bill['due_date']}";
        $text.="\nเปิดบิลเพื่อดู QR และยอดโอนที่ล็อกไว้ (ยอดบิล + 0.01–0.99 บาท) โอนตามยอด QR ห้ามปัดเศษ\n"
            .(new LineBotService($this->app))->portalUrl('bills')
            ."\nหากแนบสลิปในเว็บไม่ได้ ให้ส่งเลขบิลและรูปสลิปในแชต LINE นี้ให้ผู้ดูแลตรวจ หากโอนแล้วไม่ต้องโอนซ้ำ";
        return ['to'=>(string)$bill['line_user_id'],'messages'=>[['type'=>'text','text'=>$text]]];
    }

    private function validLineUserId(mixed $value): bool
    {
        return is_string($value)&&preg_match('/^U[0-9a-f]{32}$/D',$value)===1;
    }

    public function lineBindingHash(int $residentId,string $lineUserId): string
    {
        if($residentId<1||!$this->validLineUserId($lineUserId)){
            throw new \InvalidArgumentException('Invalid LINE binding identity');
        }
        return hash_hmac('sha256',"line-binding\0{$residentId}\0{$lineUserId}",$this->app->config->appKey());
    }

    public function isLineBindingVerified(int $residentId,mixed $lineUserId): bool
    {
        if($residentId<1||!$this->validLineUserId($lineUserId))return false;
        $statement=$this->app->database()->pdo()->prepare("SELECT action,details
            FROM audit_logs
            WHERE entity_type='resident' AND entity_id=?
              AND action IN ('resident.line_link_verified','resident.line_unlinked')
            ORDER BY created_at DESC,id DESC LIMIT 1");
        $statement->execute([(string)$residentId]);
        $row=$statement->fetch();
        return $this->lineBindingProofMatches($residentId,(string)$lineUserId,$row?:null);
    }

    /**
     * @param list<array{resident_id:int,line_user_id:mixed}> $bindings
     * @return array<int,bool>
     */
    public function verifiedLineBindings(array $bindings): array
    {
        $result=[];$lineIds=[];
        foreach($bindings as $binding){
            $residentId=(int)($binding['resident_id']??0);$lineUserId=$binding['line_user_id']??null;
            if($residentId<1)continue;
            $result[$residentId]=false;
            if($this->validLineUserId($lineUserId))$lineIds[$residentId]=(string)$lineUserId;
        }
        if($lineIds===[])return $result;
        $placeholders=implode(',',array_fill(0,count($lineIds),'?'));
        $statement=$this->app->database()->pdo()->prepare("SELECT audit.entity_id,audit.action,audit.details
            FROM audit_logs audit
            JOIN (
                SELECT entity_id,MAX(id) AS latest_id FROM audit_logs
                WHERE entity_type='resident'
                  AND action IN ('resident.line_link_verified','resident.line_unlinked')
                  AND entity_id IN ({$placeholders})
                GROUP BY entity_id
            ) latest ON latest.latest_id=audit.id");
        $statement->execute(array_map('strval',array_keys($lineIds)));
        foreach($statement->fetchAll() as $row){
            $residentId=(int)$row['entity_id'];
            if(isset($lineIds[$residentId]))$result[$residentId]=$this->lineBindingProofMatches($residentId,$lineIds[$residentId],$row);
        }
        return $result;
    }

    /** @param array<string,mixed>|null $row */
    private function lineBindingProofMatches(int $residentId,string $lineUserId,?array $row): bool
    {
        if(!$row||$row['action']!=='resident.line_link_verified')return false;
        $details=is_string($row['details']??null)?json_decode((string)$row['details'],true):($row['details']??null);
        $stored=is_array($details)&&is_string($details['line_user_id_hash']??null)?$details['line_user_id_hash']:'';
        return strlen($stored)===64&&hash_equals($this->lineBindingHash($residentId,$lineUserId),$stored);
    }

    public function withLineBindingLock(int $residentId,callable $callback,int $lockWaitSeconds=12): mixed
    {
        if($residentId<1||$lockWaitSeconds<0||$lockWaitSeconds>12)throw new \InvalidArgumentException('Invalid binding lock');
        return $this->app->lineOfficialAccounts()->withRegistryLock(fn()=>$this->withResidentBindingLock($residentId,$callback,$lockWaitSeconds),$lockWaitSeconds);
    }

    private function withResidentBindingLock(int $residentId,callable $callback,int $lockWaitSeconds): mixed
    {
        if($residentId<1)throw new \InvalidArgumentException('Invalid resident binding lock');
        if($lockWaitSeconds<0||$lockWaitSeconds>12)throw new \InvalidArgumentException('Invalid LINE binding lock timeout');
        $pdo=$this->app->database()->pdo();
        $name='dormitory:line:'.substr(hash_hmac('sha256',(string)$residentId,$this->app->config->appKey()),0,32);
        $acquire=$pdo->prepare('SELECT GET_LOCK(?,?)');$acquire->execute([$name,$lockWaitSeconds]);
        if((int)$acquire->fetchColumn()!==1)throw new \RuntimeException('Could not acquire LINE binding lock');
        try{return $callback();}
        finally{
            try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
            catch(\Throwable $error){error_log('[line-lock] '.$error->getMessage());}
        }
    }

    private function markTerminalFailure(int $id,string $claimToken,string $message): bool
    {
        $safe=$this->safeWorkerMessage($message,'LINE delivery cancelled');
        $statement=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
            SET status='failed',last_error=?,next_attempt_at=UTC_TIMESTAMP(6),
                claim_token=NULL,lease_until=NULL,updated_at=UTC_TIMESTAMP(6)
            WHERE id=? AND status='processing' AND claim_token=?");
        $statement->execute([$safe,$id,$claimToken]);
        return $statement->rowCount()===1;
    }

    private function storedLinePayloadBody(mixed $stored,string $recipient): string
    {
        if(!is_string($stored)||$stored===''||strlen($stored)>65536){
            throw new LineDeliveryException('Stored LINE payload is missing or exceeds the size limit',false);
        }
        try{$payload=json_decode($stored,false,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new LineDeliveryException('Stored LINE payload is not valid JSON',false);}
        if(!$payload instanceof \stdClass||!is_string($payload->to??null)||!hash_equals($recipient,$payload->to)){
            throw new LineDeliveryException('Stored LINE payload recipient does not match the immutable outbox recipient',false);
        }
        $messages=$payload->messages??null;
        if(!is_array($messages)||count($messages)<1||count($messages)>5){
            throw new LineDeliveryException('Stored LINE payload has an invalid messages list',false);
        }
        foreach($messages as $message){
            if(!$message instanceof \stdClass||($message->type??null)!=='text'||!is_string($message->text??null)||trim($message->text)===''){
                throw new LineDeliveryException('Stored LINE payload contains an invalid message',false);
            }
        }
        return $stored;
    }

    /** @return array{request_id:?string,accepted_request_id:?string,status:int} */
    public function pushTextForAccount(int $oaId,string $recipient,string $text,string $retryKey): array
    {
        if(!$this->validLineUserId($recipient)||$text===''||mb_strlen($text)>4500)throw new LineDeliveryException('Invalid LINE text',false);
        return $this->app->lineOfficialAccounts()->withRegistryLock(function()use($oaId,$recipient,$text,$retryKey):array{
            $oa=$this->app->lineOfficialAccounts()->credentials($oaId);
            return $this->pushLine(json_encode(['to'=>$recipient,'messages'=>[['type'=>'text','text'=>$text]]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$retryKey,$oa['access_token']);
        });
    }

    private function pushLine(string $body,string $retryKey,?string $accountToken=null): array
    {
        $token=$accountToken??trim((string)$this->app->settings()->value('line_channel_access_token',''));
        if($token==='')throw new LineDeliveryException('LINE Messaging is not configured',false);
        if(!$this->validRetryUuid($retryKey))throw new LineDeliveryException('X-Line-Retry-Key is invalid',false);
        if($body===''||strlen($body)>65536)throw new LineDeliveryException('LINE request body is invalid',false);
        if($this->pushTransport!==null)return ($this->pushTransport)($body,$retryKey,$token);
        if(!function_exists('curl_init'))throw new LineDeliveryException('PHP cURL extension is required',false);
        $ch=curl_init(self::LINE_ENDPOINT);if($ch===false)throw new LineDeliveryException('Cannot initialize LINE cURL request',true);
        $response='';$tooLarge=false;$providerHeaders=[];
        $configured=curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','X-Line-Retry-Key: '.$retryKey],CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$providerHeaders):int{$length=strlen($line);$separator=strpos($line,':');if($separator===false)return $length;$name=strtolower(trim(substr($line,0,$separator)));if(!in_array($name,['x-line-request-id','x-line-accepted-request-id'],true))return $length;$value=trim(substr($line,$separator+1));if($value!==''&&strlen($value)<=128&&preg_match('/^[\x21-\x7E]+$/D',$value)===1)$providerHeaders[$name]=$value;return $length;},CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{if(strlen($response)+strlen($chunk)>65536){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
        if(!$configured){curl_close($ch);throw new LineDeliveryException('Cannot configure LINE cURL request',true);}
        $executed=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$curlError=(int)curl_errno($ch);curl_close($ch);
        if($tooLarge)throw new LineDeliveryException('LINE response exceeded the size limit',false,$status>0?$status:null);
        if($executed===false)throw new LineDeliveryException('LINE network request failed (cURL '.$curlError.')',true,$status>0?$status:null);
        if($status>=200&&$status<300){
            return [
                'request_id'=>$providerHeaders['x-line-request-id']??null,
                'accepted_request_id'=>$providerHeaders['x-line-accepted-request-id']??null,
                'status'=>$status,
            ];
        }
        // A retry-key collision proves prior acceptance only when LINE returns
        // the accepted request identifier. Never turn an unproven conflict
        // into a successful notification.
        if($status===409){
            $accepted=$providerHeaders['x-line-accepted-request-id']??null;
            if(is_string($accepted)&&$accepted!==''){
                return [
                    'request_id'=>$providerHeaders['x-line-request-id']??null,
                    'accepted_request_id'=>$accepted,
                    'status'=>$status,
                ];
            }
            throw new LineDeliveryException(
                'LINE retry conflict did not include an accepted request ID',
                false,
                $status
            );
        }
        if(in_array($status,[408,429],true)){
            throw new LineDeliveryException(
                'LINE API temporarily rejected the request (HTTP '.$status.')',
                true,
                $status
            );
        }
        if($status>=500&&$status<=599)throw new LineDeliveryException('LINE API is temporarily unavailable (HTTP '.$status.')',true,$status);
        throw new LineDeliveryException('LINE API rejected the request (HTTP '.$status.')',false,$status);
    }

    private function validRetryUuid(mixed $value): bool
    {
        return is_string($value)&&preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$value)===1;
    }

    private function randomUuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }
}
