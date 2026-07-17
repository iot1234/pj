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

    public function __construct(private readonly Application $app) {}

    public function sendLineLinkCode(string $lineUserId,string $code): void
    {
        if(!$this->validLineUserId($lineUserId)||preg_match('/^\d{6}$/D',$code)!==1){
            throw new HttpException(422,'Invalid LINE link request','VALIDATION_ERROR');
        }
        if(trim((string)$this->app->settings()->value('line_channel_access_token',''))===''){
            throw new HttpException(503,'ยังไม่ได้ตั้งค่า LINE Messaging','LINE_NOT_CONFIGURED');
        }
        $payload=[
            'to'=>$lineUserId,
            'messages'=>[['type'=>'text','text'=>"รหัสยืนยันการรับบิล: {$code}\nรหัสหมดอายุใน 10 นาที หากคุณไม่ได้ร้องขอ ไม่ต้องดำเนินการใด ๆ"]],
        ];
        $this->pushLine($payload,$this->randomUuid());
    }

    /** @return array<string,mixed> */
    public function enqueueBill(int $billId): array
    {
        return $this->app->database()->transaction(function(PDO $pdo) use($billId): array {
            $statement=$pdo->prepare("SELECT b.id,b.bill_no,b.period,b.due_date,b.total_amount,b.status,
                    b.room_code_snapshot AS room_code,res.id AS resident_id,res.line_user_id
                FROM bills b
                JOIN residents res ON res.id=b.resident_id
                WHERE b.id=? FOR UPDATE");
            $statement->execute([$billId]);
            $bill=$statement->fetch();
            if(!$bill)throw new HttpException(404,'ไม่พบบิล','BILL_NOT_FOUND');
            if($bill['status']!=='pending'){
                throw new HttpException(409,'บิลนี้ชำระแล้ว จึงไม่สามารถเข้าคิวแจ้งชำระได้','BILL_NOT_PENDING',['status'=>$bill['status']]);
            }
            if(trim((string)$this->app->settings()->value('line_channel_access_token',''))===''){
                throw new HttpException(503,'ยังไม่ได้ตั้งค่า LINE Messaging','LINE_NOT_CONFIGURED');
            }
            if(!$this->validLineUserId($bill['line_user_id']??null)){
                throw new HttpException(422,'ผู้พักยังไม่ได้ผูกบัญชี LINE','LINE_NOT_LINKED');
            }
            if(!$this->isLineBindingVerified((int)$bill['resident_id'],(string)$bill['line_user_id'])){
                throw new HttpException(422,'ผู้พักต้องยืนยันบัญชี LINE ด้วยรหัสครั้งเดียวก่อนรับบิล','LINE_NOT_VERIFIED');
            }

            $payload=$this->billPayload($bill);
            $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $existingStatement=$pdo->prepare("SELECT id,status FROM notification_outbox WHERE bill_id=? AND purpose='bill_delivery' LIMIT 1 FOR UPDATE");
            $existingStatement->execute([$billId]);
            $existing=$existingStatement->fetch();
            $enqueueState='newly_queued';

            if(!$existing){
                $insert=$pdo->prepare("INSERT INTO notification_outbox
                    (bill_id,resident_id,channel,purpose,recipient,payload,status,attempts,next_attempt_at,retry_key,created_at,updated_at)
                    VALUES (?,?,'line','bill_delivery',?,?,'pending',0,UTC_TIMESTAMP(),?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$billId,$bill['resident_id'],$bill['line_user_id'],$encoded,$this->retryUuid($billId)]);
                $outboxId=(int)$pdo->lastInsertId();
            }else{
                $outboxId=(int)$existing['id'];
                $enqueueState='already_'.$existing['status'];
                if($existing['status']==='failed'){
                    $update=$pdo->prepare("UPDATE notification_outbox
                        SET resident_id=?,recipient=?,payload=?,status='pending',attempts=0,last_error=NULL,
                            next_attempt_at=UTC_TIMESTAMP(),sent_at=NULL,updated_at=UTC_TIMESTAMP()
                        WHERE id=? AND status='failed'");
                    $update->execute([$bill['resident_id'],$bill['line_user_id'],$encoded,$outboxId]);
                    $enqueueState='requeued';
                }elseif($existing['status']==='pending'){
                    $update=$pdo->prepare("UPDATE notification_outbox
                        SET resident_id=?,recipient=?,payload=?,updated_at=UTC_TIMESTAMP()
                        WHERE id=? AND status='pending'");
                    $update->execute([$bill['resident_id'],$bill['line_user_id'],$encoded,$outboxId]);
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

    /** @return array{processed:int,sent:int,failed:int,retried:int} */
    public function process(int $limit=25): array
    {
        $limit=max(1,min(100,$limit));
        $this->app->database()->pdo()->exec("UPDATE notification_outbox SET status='pending',next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE status='processing' AND updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)");
        $ids=$this->app->database()->transaction(function(PDO $pdo) use($limit): array {
            $rows=$pdo->query("SELECT id FROM notification_outbox WHERE status='pending' AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id LIMIT {$limit} FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
            if($rows!==[]){$marks=implode(',',array_fill(0,count($rows),'?'));$pdo->prepare("UPDATE notification_outbox SET status='processing',attempts=attempts+1,updated_at=UTC_TIMESTAMP() WHERE id IN ({$marks})")->execute($rows);}
            return array_map('intval',$rows);
        });
        $result=['processed'=>0,'sent'=>0,'failed'=>0,'retried'=>0];$max=max(1,min(20,$this->app->settings()->intValue('line_max_attempts',5)));
        foreach($ids as $id){
            $get=$this->app->database()->pdo()->prepare("SELECT n.id,n.retry_key,n.attempts,b.status AS bill_status,b.bill_no,b.period,b.due_date,b.total_amount,
                    b.room_code_snapshot AS room_code,res.id AS resident_id,res.line_user_id
                FROM notification_outbox n
                JOIN bills b ON b.id=n.bill_id
                JOIN residents res ON res.id=b.resident_id
                WHERE n.id=? AND n.status='processing'");
            $get->execute([$id]);
            $row=$get->fetch();
            $result['processed']++;
            if(!$row){
                $this->markTerminalFailure($id,'LINE delivery data is incomplete');
                $result['failed']++;
                continue;
            }
            try{
                $delivery=$this->withLineBindingLock((int)$row['resident_id'],function()use($id):array{
                    // Reload after acquiring the binding lock. Confirm/unlink
                    // use the same lock, so a successful change cannot race a
                    // later delivery to the old recipient.
                    $get=$this->app->database()->pdo()->prepare("SELECT n.id,n.retry_key,n.attempts,b.status AS bill_status,b.bill_no,b.period,b.due_date,b.total_amount,
                            b.room_code_snapshot AS room_code,res.id AS resident_id,res.line_user_id
                        FROM notification_outbox n
                        JOIN bills b ON b.id=n.bill_id
                        JOIN residents res ON res.id=b.resident_id
                        WHERE n.id=? AND n.status='processing'");
                    $get->execute([$id]);$current=$get->fetch();
                    if(!$current)return ['terminal'=>'LINE delivery data is incomplete'];
                    if($current['bill_status']!=='pending')return ['terminal'=>'ยกเลิกการส่ง เนื่องจากบิลชำระแล้ว'];
                    if(!$this->validLineUserId($current['line_user_id']??null))return ['terminal'=>'ยกเลิกการส่ง เนื่องจากผู้พักไม่ได้ผูกบัญชี LINE'];
                    if(!$this->isLineBindingVerified((int)$current['resident_id'],(string)$current['line_user_id']))return ['terminal'=>'ยกเลิกการส่ง เนื่องจากบัญชี LINE ยังไม่ผ่านการยืนยัน'];
                    $payload=$this->billPayload($current);
                    $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                    $refresh=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
                        SET resident_id=?,recipient=?,payload=?,updated_at=UTC_TIMESTAMP()
                        WHERE id=? AND status='processing'");
                    $refresh->execute([$current['resident_id'],$current['line_user_id'],$encoded,$id]);
                    $this->pushLine($payload,(string)$current['retry_key']);
                    $sent=$this->app->database()->pdo()->prepare("UPDATE notification_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'");
                    $sent->execute([$id]);
                    return ['sent'=>$sent->rowCount()===1];
                });
                if(is_string($delivery['terminal']??null)){
                    $this->markTerminalFailure($id,$delivery['terminal']);$result['failed']++;
                }elseif(($delivery['sent']??false)===true)$result['sent']++;
            }catch(\Throwable $e){
                $attempts=(int)$row['attempts'];$terminal=$attempts>=$max;$delay=min(3600,30*(2**min(6,max(0,$attempts-1))));
                $message=substr(preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)$e->getMessage())??'LINE delivery failed',0,1000);
                $sql=$terminal?"UPDATE notification_outbox SET status='failed',last_error=?,next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'":"UPDATE notification_outbox SET status='pending',last_error=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$delay} SECOND),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'";
                $this->app->database()->pdo()->prepare($sql)->execute([$message,$id]);
                $terminal?$result['failed']++:$result['retried']++;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $bill @return array<string,mixed> */
    private function billPayload(array $bill): array
    {
        $text="ใบแจ้งหนี้ {$bill['bill_no']}\nห้อง {$bill['room_code']}\nรอบบิล ".substr((string)$bill['period'],0,7)."\nยอดชำระ {$bill['total_amount']} บาท\nครบกำหนด {$bill['due_date']}";
        return ['to'=>(string)$bill['line_user_id'],'messages'=>[['type'=>'text','text'=>$text]]];
    }

    private function validLineUserId(mixed $value): bool
    {
        return is_string($value)&&preg_match('/^U[0-9A-Za-z_-]{20,80}$/D',$value)===1;
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

    public function withLineBindingLock(int $residentId,callable $callback): mixed
    {
        if($residentId<1)throw new \InvalidArgumentException('Invalid resident binding lock');
        $pdo=$this->app->database()->pdo();
        $name='dormitory:line:'.substr(hash_hmac('sha256',(string)$residentId,$this->app->config->appKey()),0,32);
        $acquire=$pdo->prepare('SELECT GET_LOCK(?,12)');$acquire->execute([$name]);
        if((int)$acquire->fetchColumn()!==1)throw new \RuntimeException('Could not acquire LINE binding lock');
        try{return $callback();}
        finally{
            try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);}
            catch(\Throwable $error){error_log('[line-lock] '.$error->getMessage());}
        }
    }

    private function markTerminalFailure(int $id,string $message): void
    {
        $safe=substr(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$message)??'LINE delivery cancelled',0,1000);
        $statement=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
            SET status='failed',last_error=?,next_attempt_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
            WHERE id=? AND status='processing'");
        $statement->execute([$safe,$id]);
    }

    /** @param array<string,mixed> $payload */
    private function pushLine(array $payload,string $retryKey): void
    {
        $token=trim((string)$this->app->settings()->value('line_channel_access_token',''));if($token==='')throw new \RuntimeException('LINE Messaging is not configured');
        if(!function_exists('curl_init'))throw new \RuntimeException('PHP cURL extension is required');
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $ch=curl_init(self::LINE_ENDPOINT);if($ch===false)throw new \RuntimeException('Cannot initialize cURL');
        $response='';$tooLarge=false;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json','X-Line-Retry-Key: '.$retryKey],CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{if(strlen($response)+strlen($chunk)>65536){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
        $executed=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($tooLarge)throw new \RuntimeException('LINE response exceeded limit');if($executed===false)throw new \RuntimeException('LINE request failed: '.$error);
        // LINE returns 409 when the same retry UUID was already accepted. Treat it as delivered.
        if(($status<200||$status>=300)&&$status!==409)throw new \RuntimeException('LINE API returned HTTP '.$status.': '.substr($response,0,300));
    }

    private function retryUuid(int $billId): string
    {
        $bytes=substr(hash_hmac('sha256','line:bill_delivery:'.$billId,$this->app->config->appKey(),true),0,16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    private function randomUuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }
}
