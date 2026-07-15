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
            if($row['bill_status']!=='pending'){
                $this->markTerminalFailure($id,'ยกเลิกการส่ง เนื่องจากบิลชำระแล้ว');
                $result['failed']++;
                continue;
            }
            if(!$this->validLineUserId($row['line_user_id']??null)){
                $this->markTerminalFailure($id,'ยกเลิกการส่ง เนื่องจากผู้พักไม่ได้ผูกบัญชี LINE');
                $result['failed']++;
                continue;
            }

            try{
                // Rebuild from the latest bill and resident records immediately
                // before the network call. No database transaction is held while
                // LINE is contacted.
                $payload=$this->billPayload($row);
                $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $refresh=$this->app->database()->pdo()->prepare("UPDATE notification_outbox
                    SET resident_id=?,recipient=?,payload=?,updated_at=UTC_TIMESTAMP()
                    WHERE id=? AND status='processing'");
                $refresh->execute([$row['resident_id'],$row['line_user_id'],$encoded,$id]);
                $this->pushLine($payload,(string)$row['retry_key']);
                $sent=$this->app->database()->pdo()->prepare("UPDATE notification_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'");
                $sent->execute([$id]);
                if($sent->rowCount()===1)$result['sent']++;
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
}
