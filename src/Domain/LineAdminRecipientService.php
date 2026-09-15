<?php
declare(strict_types=1);
namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\Validator;
use PDO;

final class LineAdminRecipientService
{
    public const CATEGORIES=['booking','payment','billing','tenancy','maintenance','security','system'];
    public function __construct(private readonly Application $app) {}

    public function all(): array
    {
        $rows=$this->app->database()->pdo()->query('SELECT * FROM line_admin_recipients WHERE revoked_at IS NULL ORDER BY id DESC LIMIT 200')->fetchAll();
        return array_map(fn(array $row):array=>$this->safe($row,false),$rows);
    }

    public function get(int $id): array { return $this->safe($this->row($id),true); }

    public function issue(array $input,int $adminId): array
    {
        Validator::only($input,['oa_id','label','is_owner']);
        $oaId=$this->oaId($input['oa_id']??$this->app->lineOfficialAccounts()->defaultId());
        $label=Validator::string($input['label']??'ผู้รับแจ้งเตือน','label',1,120);
        $owner=$input['is_owner']??false;if(!is_bool($owner))throw new HttpException(422,'is_owner ต้องเป็น boolean','VALIDATION_ERROR');
        return $this->app->lineOfficialAccounts()->withRegistryLock(function()use($oaId,$label,$owner,$adminId):array{
            $this->app->lineOfficialAccounts()->credentials($oaId);
            return $this->app->database()->transaction(function(PDO $pdo)use($oaId,$label,$owner,$adminId):array{
                if($owner)$pdo->prepare('UPDATE line_admin_recipients SET revoked_at=UTC_TIMESTAMP(6),enabled=0,code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE oa_id=? AND is_owner=1 AND claimed_at IS NULL AND revoked_at IS NULL')->execute([$oaId]);
                $code=($owner?'OWNER-':'ADMIN-').strtoupper(bin2hex(random_bytes(16)));
                $ttl=$owner?300:600;
                $pdo->prepare("INSERT INTO line_admin_recipients(oa_id,label,is_owner,enabled,muted_categories,code_hash,expires_at,created_by,created_at,updated_at)
                    VALUES(?,?,?,1,JSON_ARRAY(),?,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$ttl} SECOND),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
                    ->execute([$oaId,$label,(int)$owner,$this->hash($code),$adminId]);
                $id=(int)$pdo->lastInsertId();
                $encrypted=(new SecretCipher($this->app->config))->encrypt($code,'line_admin_code_'.$id);
                $pdo->prepare('UPDATE line_admin_recipients SET code_enc=? WHERE id=?')->execute([$encrypted,$id]);
                $this->audit($adminId,'line.admin_recipient.issued',$id,['oa_id'=>$oaId,'is_owner'=>$owner]);
                return $this->get($id);
            });
        });
    }

    public function update(int $id,array $input,int $adminId): array
    {
        Validator::only($input,['label','enabled','muted_categories']);
        return $this->app->lineOfficialAccounts()->withRegistryLock(fn()=>$this->app->database()->transaction(function(PDO $pdo)use($id,$input,$adminId):array{
            $row=$this->row($id,true);if($row['revoked_at']!==null)throw new HttpException(409,'ผู้รับนี้ถูกยกเลิกแล้ว กรุณาออกคีย์ใหม่','LINE_RECIPIENT_REVOKED');
            $label=array_key_exists('label',$input)?Validator::string($input['label'],'label',1,120):$row['label'];
            $enabled=array_key_exists('enabled',$input)?$input['enabled']:(bool)$row['enabled'];if(!is_bool($enabled))throw new HttpException(422,'enabled ต้องเป็น boolean','VALIDATION_ERROR');
            $mutes=array_key_exists('muted_categories',$input)?$input['muted_categories']:json_decode($row['muted_categories'],true);
            if(!is_array($mutes)||!array_is_list($mutes))throw new HttpException(422,'หมวดแจ้งเตือนไม่ถูกต้อง','VALIDATION_ERROR');
            foreach($mutes as$category)if(!is_string($category)||!in_array($category,self::CATEGORIES,true))throw new HttpException(422,'หมวดแจ้งเตือนไม่ถูกต้อง','VALIDATION_ERROR');
            if(count($mutes)!==count(array_unique($mutes)))throw new HttpException(422,'หมวดแจ้งเตือนไม่ถูกต้อง','VALIDATION_ERROR');
            $pdo->prepare('UPDATE line_admin_recipients SET label=?,enabled=?,muted_categories=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$label,(int)$enabled,json_encode($mutes,JSON_THROW_ON_ERROR),$id]);
            $this->audit($adminId,'line.admin_recipient.updated',$id,['enabled'=>$enabled,'muted_categories'=>$mutes]);return $this->get($id);
        }));
    }

    public function revoke(int $id,int $adminId): array
    {
        return $this->app->lineOfficialAccounts()->withRegistryLock(fn()=>$this->app->database()->transaction(function(PDO $pdo)use($id,$adminId):array{
            $this->row($id,true);
            $pdo->prepare('UPDATE line_admin_recipients SET enabled=0,revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6)),code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$id]);
            $this->audit($adminId,'line.admin_recipient.revoked',$id,[]);return $this->get($id);
        }));
    }

    public function consume(string $code,string $lineUserId,int $oaId): array
    {
        if(!preg_match('/^(OWNER|ADMIN)-[A-F0-9]{32}$/D',$code)||!preg_match('/^U[0-9a-f]{32}$/D',$lineUserId))throw new HttpException(422,'คีย์ผู้รับแจ้งเตือนไม่ถูกต้อง','LINE_ADMIN_CODE_INVALID');
        // Reentrant when called by the signed webhook; direct service callers
        // must obey the same OA/owner replacement serialization boundary.
        return $this->app->lineOfficialAccounts()->withRegistryLock(function()use($code,$lineUserId,$oaId):array{
          $this->app->lineOfficialAccounts()->credentials($oaId);
          return $this->app->database()->transaction(function(PDO $pdo)use($code,$lineUserId,$oaId):array{
            $q=$pdo->prepare('SELECT *,expires_at>UTC_TIMESTAMP(6) AS valid FROM line_admin_recipients WHERE code_hash=? FOR UPDATE');$q->execute([$this->hash($code)]);$row=$q->fetch();
            if(!$row||$row['revoked_at']!==null||(int)$row['oa_id']!==$oaId)throw new HttpException(422,'คีย์ไม่ถูกต้องหรือส่งผิด LINE OA กรุณาขอคีย์ใหม่','LINE_ADMIN_CODE_INVALID');
            if(!(bool)$row['enabled'])throw new HttpException(409,'คีย์ผู้รับแจ้งเตือนนี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแล','LINE_RECIPIENT_DISABLED');
            if($row['claimed_at']!==null){if(hash_equals((string)$row['line_user_id'],$lineUserId))return ['id'=>(int)$row['id'],'newly_claimed'=>false];throw new HttpException(409,'คีย์ถูกใช้แล้ว','LINE_ADMIN_CODE_USED');}
            if(!(bool)$row['valid'])throw new HttpException(422,'คีย์หมดอายุ กรุณาขอคีย์ใหม่','LINE_ADMIN_CODE_EXPIRED');
            if((bool)$row['is_owner'])$pdo->prepare('UPDATE line_admin_recipients SET enabled=0,revoked_at=UTC_TIMESTAMP(6),code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE oa_id=? AND is_owner=1 AND claimed_at IS NOT NULL AND revoked_at IS NULL')->execute([$oaId]);
            // The same user can be represented by both an owner and a staff
            // claim, but delivery deduplicates by OA + recipient.
            $pdo->prepare('UPDATE line_admin_recipients SET line_user_id=?,claimed_at=UTC_TIMESTAMP(6),code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?')->execute([$lineUserId,$row['id']]);
            $this->audit(0,'line.admin_recipient.claimed',(int)$row['id'],['oa_id'=>$oaId,'recipient_hash'=>hash_hmac('sha256',$lineUserId,$this->app->config->appKey())]);
            return ['id'=>(int)$row['id'],'newly_claimed'=>true];
          });
        });
    }

    public function eligible(string $category,?int $oaId=null): array
    {
        if(!in_array($category,self::CATEGORIES,true))throw new \InvalidArgumentException('Invalid notice category');
        $q=$this->app->database()->pdo()->prepare('SELECT * FROM line_admin_recipients WHERE enabled=1 AND revoked_at IS NULL AND claimed_at IS NOT NULL'.($oaId!==null?' AND oa_id=?':'').' ORDER BY id');$q->execute($oaId!==null?[$oaId]:[]);
        $rows=[];$seen=[];
        foreach($q->fetchAll()as$row){$key=$row['oa_id'].':'.$row['line_user_id'];if(isset($seen[$key])||in_array($category,json_decode($row['muted_categories'],true)??[],true))continue;$seen[$key]=true;$rows[]=$row;}
        return $rows;
    }

    public function mayDeliver(int $id,string $user,int $oaId,string $category): bool
    {
        $row=$this->row($id);return (int)$row['oa_id']===$oaId&&(bool)$row['enabled']&&$row['revoked_at']===null&&$row['claimed_at']!==null&&hash_equals((string)$row['line_user_id'],$user)&&!in_array($category,json_decode($row['muted_categories'],true)??[],true);
    }

    private function row(int $id,bool $lock=false): array
    {
        $q=$this->app->database()->pdo()->prepare('SELECT *,expires_at>UTC_TIMESTAMP(6) AS valid FROM line_admin_recipients WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch()?:throw new HttpException(404,'ไม่พบผู้รับแจ้งเตือน','LINE_RECIPIENT_NOT_FOUND');
    }
    private function safe(array $row,bool $showCode): array
    {
        $oa=$this->app->lineOfficialAccounts()->get((int)$row['oa_id']);
        $pending=$row['claimed_at']===null&&$row['revoked_at']===null&&(isset($row['valid'])?(bool)$row['valid']:strtotime($row['expires_at'].' UTC')>time());
        $result=['id'=>(int)$row['id'],'oa_id'=>(int)$row['oa_id'],'oa_name'=>$oa['name'],'label'=>$row['label'],'is_owner'=>(bool)$row['is_owner'],'enabled'=>(bool)$row['enabled'],'muted_categories'=>json_decode($row['muted_categories'],true),'line_user_id_hint'=>$row['line_user_id']?'•••'.substr($row['line_user_id'],-6):null,'expires_at'=>$row['expires_at'],'claimed_at'=>$row['claimed_at'],'status'=>$row['revoked_at']!==null?'revoked':($row['claimed_at']!==null?'claimed':($pending?'pending':'expired'))];
        if($pending&&$showCode&&is_string($row['code_enc'])){
            $code=(new SecretCipher($this->app->config))->decrypt($row['code_enc'],'line_admin_code_'.$row['id']);
            $basic=$oa['basic_id']??null;$result['code']=$code;$result['line_add_friend_url']=$oa['line_add_friend_url']??null;
            $result['line_message_url']=is_string($basic)&&preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$basic)?'https://line.me/R/oaMessage/'.rawurlencode($basic).'/?'.rawurlencode($code):null;
        }
        return $result;
    }
    private function hash(string $code): string{return hash_hmac('sha256',"line-admin-claim\0".$code,$this->app->config->appKey());}
    private function oaId(mixed $id): int{if(!is_int($id)||$id<0)throw new HttpException(422,'oa_id ไม่ถูกต้อง','VALIDATION_ERROR');return $id;}
    private function audit(int $adminId,string $action,int $id,array $details): void
    {
        $r=new Request('INTERNAL','/line/admin-recipients',[],[],[],[],[],bin2hex(random_bytes(16)));
        $this->app->audit()->writeStrict($r,$adminId>0?['id'=>$adminId,'type'=>'admin']:null,$action,'line_admin_recipient',$id,$details);
    }
}
