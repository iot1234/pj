<?php
declare(strict_types=1);
namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Security\SecretCipher;
use PDO;

/** Transactional queue for binding changes and explicitly claimed admin recipients. */
final class LineNoticeService
{
    public function __construct(private readonly Application $app) {}

    public function enqueueLifecycle(array $account,string $text): int
    {
        return $this->enqueue((int)$account['oa_id'],(string)$account['line_user_id'],'tenancy',$text,
            isset($account['resident_id'])?(int)$account['resident_id']:null,
            !empty($account['id'])?(int)$account['id']:null,null,null);
    }

    public function enqueueAdmin(string $category,string $text,string $eventKey,?int $oaId=null): int
    {
        $count=0;
        foreach($this->app->lineAdminRecipients()->eligible($category,$oaId)as$row){
            $key=hash_hmac('sha256',"line-admin-notice\0{$eventKey}\0{$row['oa_id']}\0{$row['line_user_id']}",$this->app->config->appKey());
            $this->enqueue((int)$row['oa_id'],$row['line_user_id'],$category,$text,null,null,(int)$row['id'],$key);$count++;
        }
        return $count;
    }

    private function enqueue(int $oaId,string $recipient,string $category,string $message,?int $residentId,?int $bindingId,?int $adminRecipientId,?string $dedupeKey): int
    {
        if(!preg_match('/^U[0-9a-f]{32}$/D',$recipient)||!in_array($category,LineAdminRecipientService::CATEGORIES,true)||mb_strlen($message)>4500||$message==='')throw new \InvalidArgumentException('Invalid LINE notice');
        $key=self::uuid();$encrypted=(new SecretCipher($this->app->config))->encrypt($message,'line_notice_'.str_replace('-','',$key));
        $q=$this->app->database()->pdo()->prepare("INSERT INTO line_notice_outbox(oa_id,resident_id,binding_id,admin_recipient_id,recipient,category,message_enc,status,attempts,retry_key,dedupe_key,next_attempt_at,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,'pending',0,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $q->execute([$oaId,$residentId,$bindingId,$adminRecipientId,$recipient,$category,$encrypted,$key,$dedupeKey]);
        return (int)$this->app->database()->pdo()->lastInsertId();
    }

    public function process(int $limit=10): array
    {
        $pdo=$this->app->database()->pdo();
        $recover=$pdo->exec("UPDATE line_notice_outbox SET status='pending',claim_token=NULL,lease_until=NULL,next_attempt_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE status='processing' AND lease_until<=UTC_TIMESTAMP(6)");
        $result=['processed'=>0,'sent'=>0,'failed'=>0,'retried'=>0,'lost_claims'=>0,'recovered'=>$recover];
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $row=$this->app->database()->transaction(function(PDO $pdo):?array{
                $q=$pdo->query("SELECT * FROM line_notice_outbox WHERE status='pending' AND next_attempt_at<=UTC_TIMESTAMP(6) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");$row=$q->fetch();if(!$row)return null;
                $row['claim_token']=bin2hex(random_bytes(32));$row['attempts']=(int)$row['attempts']+1;
                $pdo->prepare("UPDATE line_notice_outbox SET status='processing',claim_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 120 SECOND),attempts=attempts+1,updated_at=UTC_TIMESTAMP(6) WHERE id=?")->execute([$row['claim_token'],$row['id']]);return $row;
            });
            if($row===null)break;$result['processed']++;
            try{
                $sent=$this->app->lineOfficialAccounts()->withRegistryLock(function()use($row):bool{
                    return $this->app->database()->transaction(function(PDO $pdo)use($row):bool{
                        $q=$pdo->prepare("SELECT *,created_at>DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 24 HOUR) AS fresh FROM line_notice_outbox WHERE id=? AND status='processing' AND claim_token=? AND lease_until>UTC_TIMESTAMP(6) FOR UPDATE");$q->execute([$row['id'],$row['claim_token']]);$current=$q->fetch();if(!$current)return false;
                        if(!(bool)$current['fresh'])throw new LineDeliveryException('Notice expired',false);
                        if($current['admin_recipient_id']!==null&&!$this->app->lineAdminRecipients()->mayDeliver((int)$current['admin_recipient_id'],$current['recipient'],(int)$current['oa_id'],$current['category']))throw new LineDeliveryException('Admin recipient disabled or revoked',false);
                        $message=(new SecretCipher($this->app->config))->decrypt($current['message_enc'],'line_notice_'.str_replace('-','',$current['retry_key']));
                        $this->app->notifications()->pushTextForAccount((int)$current['oa_id'],$current['recipient'],$message,$current['retry_key']);
                        $pdo->prepare("UPDATE line_notice_outbox SET status='sent',sent_at=UTC_TIMESTAMP(6),claim_token=NULL,lease_until=NULL,last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND claim_token=?")->execute([$row['id'],$row['claim_token']]);return true;
                    });
                });
                $result[$sent?'sent':'lost_claims']++;
            }catch(\Throwable $error){
                $retry=!($error instanceof LineDeliveryException)||$error->retryable;
                $retry=$retry&&(int)$row['attempts']<5;
                $delay=min(900,30*(2**min(5,(int)$row['attempts'])));
                $q=$pdo->prepare("UPDATE line_notice_outbox SET status=?,claim_token=NULL,lease_until=NULL,last_error=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$delay} SECOND),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='processing' AND claim_token=?");
                $q->execute([$retry?'pending':'failed',$retry?'LINE_NOTICE_RETRY':'LINE_NOTICE_UNAVAILABLE',$row['id'],$row['claim_token']]);
                $result[$q->rowCount()===0?'lost_claims':($retry?'retried':'failed')]++;
            }
        }
        return $result;
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
