<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\MySqlError;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

/** OA 0 is a compatibility adapter; positive IDs own their encrypted secrets. */
final class LineOfficialAccountService
{
    private const FIELDS = ['slug','name','description','basic_id','channel_id','add_friend_url','channel_access_token','channel_access_token_clear','channel_secret','channel_secret_clear','enabled'];
    private readonly SecretCipher $cipher;
    private int $registryDepth = 0;

    public function __construct(private readonly Application $app, private readonly ?\Closure $identityTransport = null)
    {
        if ($identityTransport !== null && (PHP_SAPI !== 'cli' || $app->config->get('APP_ENV') !== 'testing')) {
            throw new \InvalidArgumentException('LINE identity transport injection is restricted to CLI tests');
        }
        $this->cipher = new SecretCipher($app->config);
    }

    public function withRegistryLock(callable $callback, int $waitSeconds = 12): mixed
    {
        if ($waitSeconds < 0 || $waitSeconds > 12) throw new \InvalidArgumentException('Invalid LINE registry lock timeout');
        if ($this->registryDepth > 0) {
            $this->registryDepth++;
            try { return $callback(); } finally { $this->registryDepth--; }
        }
        $pdo = $this->app->database()->pdo();
        $name = 'dormitory:line-registry:' . substr(hash_hmac('sha256', $this->app->config->require('DB_DATABASE'), $this->app->config->appKey()), 0, 24);
        $statement = $pdo->prepare('SELECT GET_LOCK(?,?)'); $statement->execute([$name,$waitSeconds]);
        if ((int)$statement->fetchColumn() !== 1) throw new HttpException(503,'ระบบ LINE กำลังทำงาน กรุณาลองใหม่','LINE_REGISTRY_BUSY');
        $this->registryDepth = 1;
        try { return $callback(); }
        finally {
            $this->registryDepth = 0;
            try { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$name]); }
            catch (\Throwable) { error_log('[line-registry] Unable to release registry lock'); }
        }
    }

    public function all(): array
    {
        $rows=$this->app->database()->pdo()->query('SELECT * FROM line_official_accounts WHERE deleted_at IS NULL ORDER BY is_default DESC,id')->fetchAll();
        return array_map($this->safeRow(...),$rows);
    }

    public function get(int $id): array { return $this->safeRow($this->row($id,false,true)); }

    /** Internal only: never serialize this return value into a response or audit. */
    public function credentials(int $id): array
    {
        $row=$this->row($id);
        if (!(bool)$row['enabled'] || $row['deleted_at']!==null) throw new HttpException(409,'บัญชี LINE นี้ถูกปิดใช้งาน','LINE_OA_DISABLED');
        $secrets=$this->secrets($row);
        if ($secrets['access_token']==='' || $secrets['channel_secret']==='') throw new HttpException(503,'กรุณาตั้งค่า Channel access token และ Channel secret ให้ครบ','LINE_NOT_CONFIGURED');
        return ['id'=>$id,'basic_id'=>$this->basicId($row),'access_token'=>$secrets['access_token'],'channel_secret'=>$secrets['channel_secret'],
            'provider_user_id'=>$row['provider_user_id'],'legacy_route_enabled'=>(bool)$row['legacy_route_enabled'],
            'route_token'=>$row['route_token'],'enabled'=>true];
    }

    public function defaultId(): int
    {
        $id=$this->app->database()->pdo()->query('SELECT id FROM line_official_accounts WHERE is_default=1 AND enabled=1 AND deleted_at IS NULL LIMIT 1')->fetchColumn();
        if ($id===false) throw new HttpException(409,'ยังไม่ได้เลือกบัญชี LINE เริ่มต้นที่เปิดใช้งาน','LINE_DEFAULT_NOT_CONFIGURED');
        return (int)$id;
    }

    public function create(array $input,int $adminId): array
    {
        Validator::only($input,self::FIELDS); $this->adminId($adminId);
        return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($input,$adminId):array{
            $id=(int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM line_official_accounts')->fetchColumn();
            $row=['id'=>$id,'slug'=>'','name'=>'','description'=>null,'basic_id'=>null,'channel_id'=>null,'add_friend_url'=>null,
                'access_token_enc'=>null,'channel_secret_enc'=>null,'enabled'=>0,'is_default'=>0,'legacy_route_enabled'=>0,
                'route_token'=>bin2hex(random_bytes(24)),'provider_user_id'=>null,'token_fingerprint'=>null,'identity_verified_at'=>null,'last_error'=>null];
            $row=$this->merge($row,$input);
            if ((bool)$row['enabled']) $row=$this->verifyIdentity($row);
            $columns=['id','slug','name','description','basic_id','channel_id','add_friend_url','access_token_enc','channel_secret_enc','enabled','is_default','legacy_route_enabled','route_token','provider_user_id','token_fingerprint','identity_verified_at','last_error'];
            $statement=$pdo->prepare('INSERT INTO line_official_accounts ('.implode(',',$columns).',created_by,updated_by) VALUES ('.implode(',',array_fill(0,count($columns)+2,'?')).')');
            try { $statement->execute([...array_map(static fn(string $key)=>$row[$key],$columns),$adminId,$adminId]); }
            catch(PDOException $error){$this->duplicate($error);}
            $this->audit($adminId,'line.oa_created',$id,['enabled'=>(bool)$row['enabled']]);
            return $this->get($id);
        }));
    }

    public function update(int $id,array $input,int $adminId): array
    {
        Validator::only($input,self::FIELDS); $this->adminId($adminId);
        return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($id,$input,$adminId):array{
            $old=$this->row($id,true); $oldSecrets=$this->secrets($old); $oldBasicId=$this->basicId($old); $row=$this->merge($old,$input);
            if ($id===0) {
                $legacy=[];
                foreach(['basic_id'=>'line_basic_id','channel_access_token'=>'line_channel_access_token','channel_access_token_clear'=>'line_channel_access_token_clear','channel_secret'=>'line_channel_secret','channel_secret_clear'=>'line_channel_secret_clear'] as $from=>$to) {
                    if(array_key_exists($from,$input))$legacy[$to]=$input[$from];
                }
                if($legacy!==[])$this->app->settings()->update($legacy,$adminId);
            }
            $newSecrets=$this->secrets($row);
            $changedToken=$oldSecrets['access_token']!==$newSecrets['access_token'];
            $identityChanged=$changedToken || $oldBasicId!==$this->basicId($row) || $old['channel_id']!==$row['channel_id'];
            $readinessChanged=$identityChanged||!(bool)$old['enabled']||$oldSecrets['channel_secret']!==$newSecrets['channel_secret'];
            if((bool)$row['enabled']&&$readinessChanged&&($newSecrets['access_token']===''||$newSecrets['channel_secret']===''||$this->basicId($row)===null))throw new HttpException(422,'กรุณาตั้งค่า Basic ID และ Channel secret ให้ครบ หรือปิดใช้งานบัญชีก่อนลบค่า','LINE_NOT_CONFIGURED');
            if((bool)$row['enabled'] && ($identityChanged || !(bool)$old['enabled']))$row=$this->verifyIdentity($row);
            elseif($identityChanged){$row['token_fingerprint']=null;$row['identity_verified_at']=null;}
            if(!(bool)$row['enabled'])$row['is_default']=0;
            $columns=['slug','name','description','basic_id','channel_id','add_friend_url','access_token_enc','channel_secret_enc','enabled','is_default','provider_user_id','token_fingerprint','identity_verified_at','last_error'];
            $statement=$pdo->prepare('UPDATE line_official_accounts SET '.implode(',',array_map(static fn(string $key)=>$key.'=?',$columns)).',updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND deleted_at IS NULL');
            try{$statement->execute([...array_map(static fn(string $key)=>$row[$key],$columns),$adminId,$id]);}catch(PDOException $error){$this->duplicate($error);}
            $this->audit($adminId,'line.oa_updated',$id,['changed_fields'=>array_keys($input),'enabled'=>(bool)$row['enabled']]);
            return $this->get($id);
        }));
    }

    public function remove(int $id,int $adminId): array
    {
        $this->adminId($adminId);
        return $this->withRegistryLock(function()use($id,$adminId):array{
            $this->row($id);
            $pdo=$this->app->database()->pdo();
            $statement=$pdo->prepare("SELECT DISTINCT resident_id FROM line_room_bindings WHERE oa_id=? AND status IN ('pending','bound')");
            $statement->execute([$id]);$residentIds=array_map('intval',$statement->fetchAll(PDO::FETCH_COLUMN));
            if($id===0){
                $legacyIds=$pdo->query("SELECT id AS resident_id FROM residents WHERE line_user_id IS NOT NULL
                    UNION SELECT resident_id FROM line_link_codes WHERE status IN ('pending','bound')")->fetchAll(PDO::FETCH_COLUMN);
                $residentIds=array_merge($residentIds,array_map('intval',$legacyIds));
            }
            $residentIds=array_values(array_unique($residentIds));sort($residentIds,SORT_NUMERIC);
            $remove=fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($id,$adminId):array{
                $oa=$this->row($id,true);
                $bindings=$pdo->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                    WHERE oa_id=? AND status IN ('pending','bound')");
                $bindings->execute([$id]);$bindingCount=$bindings->rowCount();
                $contacts=$pdo->prepare('UPDATE line_admin_recipients SET enabled=0,revoked_at=UTC_TIMESTAMP(6),code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE oa_id=? AND revoked_at IS NULL');
                $contacts->execute([$id]);$contactCount=$contacts->rowCount();
                $legacyCount=0;$legacyCodeCount=0;
                if($id===0){
                    $legacy=$pdo->query('SELECT id,line_user_id FROM residents WHERE line_user_id IS NOT NULL ORDER BY id FOR UPDATE')->fetchAll();
                    $legacyCount=count($legacy);
                    $legacyCodeCount=$pdo->exec("UPDATE line_link_codes SET status='revoked',revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE status IN ('pending','bound')");
                    $pdo->exec('UPDATE residents SET line_user_id=NULL,updated_at=UTC_TIMESTAMP(6) WHERE line_user_id IS NOT NULL');
                    foreach($legacy as $resident){
                        $request=new Request('POST','/internal/line/oa',[],[],[],[],[],'line-oa-unlink-'.bin2hex(random_bytes(8)));
                        $this->app->audit()->writeStrict($request,['type'=>'admin','id'=>$adminId],'resident.line_unlinked','resident',(int)$resident['id'],[
                            'reason'=>'line_oa_deleted','oa_id'=>0,
                            'line_user_id_hash'=>$this->app->notifications()->lineBindingHash((int)$resident['id'],(string)$resident['line_user_id']),
                        ]);
                    }
                }
                // Archived bindings retain this OA ID. Release the live labels
                // so re-adding the same provider creates a fresh, unbound OA.
                $archivedSlug='deleted-'.$id.'-'.bin2hex(random_bytes(4));
                $statement=$pdo->prepare('UPDATE line_official_accounts SET slug=?,provider_user_id=NULL,token_fingerprint=NULL,identity_verified_at=NULL,
                    enabled=0,is_default=0,legacy_route_enabled=0,deleted_at=UTC_TIMESTAMP(6),last_seen_at=NULL,last_error=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');
                $statement->execute([$archivedSlug,$adminId,$id]);
                $this->audit($adminId,'line.oa_deleted',$id,['soft_deleted'=>true,'revoked_bindings'=>$bindingCount,'revoked_admin_recipients'=>$contactCount,
                    'unlinked_legacy_residents'=>$legacyCount,'revoked_legacy_invitations'=>$legacyCodeCount,'previous_slug'=>$oa['slug'],
                    'provider_identity_hash'=>$oa['provider_user_id']===null?null:hash_hmac('sha256',"line-oa-provider\0".$oa['provider_user_id'],$this->app->config->appKey())]);
                return ['id'=>$id,'deleted'=>true];
            });
            // Keep every resident fence until the transaction commits. Build the
            // chain in reverse so acquisition follows the same ascending order.
            foreach(array_reverse($residentIds)as$residentId){
                $next=$remove;
                $remove=fn():array=>$this->app->notifications()->withLineBindingLock($residentId,$next);
            }
            return $remove();
        });
    }

    public function setDefault(int $id,int $adminId): array
    {
        $this->adminId($adminId);
        return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($id,$adminId):array{
            $row=$this->row($id,true);
            if(!(bool)$row['enabled'])throw new HttpException(409,'กรุณาเปิดใช้งานบัญชี LINE ก่อนเลือกเป็นค่าเริ่มต้น','LINE_OA_DISABLED');
            if(!$this->safeRow($row)['line_binding_ready'])throw new HttpException(409,'กรุณาตั้งค่าบัญชี LINE ให้ครบก่อนเลือกเป็นค่าเริ่มต้น','LINE_NOT_CONFIGURED');
            $pdo->exec('UPDATE line_official_accounts SET is_default=0 WHERE is_default=1');
            $statement=$pdo->prepare('UPDATE line_official_accounts SET is_default=1,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');$statement->execute([$adminId,$id]);
            $this->audit($adminId,'line.oa_default',$id,[]); return $this->get($id);
        }));
    }

    public function rotateRoute(int $id,int $adminId): array
    {
        $this->adminId($adminId);
        return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($id,$adminId):array{
            $this->row($id,true);
            $statement=$pdo->prepare('UPDATE line_official_accounts SET route_token=?,legacy_route_enabled=0,last_seen_at=NULL,last_error=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');
            $statement->execute([bin2hex(random_bytes(24)),$adminId,$id]);
            $this->audit($adminId,'line.oa_route_rotated',$id,[]); return $this->get($id);
        }));
    }

    public function test(int $id,int $adminId): array
    {
        $this->adminId($adminId);
        return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo)use($id,$adminId):array{
            $row=$this->verifyIdentity($this->row($id,true));
            $statement=$pdo->prepare('UPDATE line_official_accounts SET provider_user_id=?,token_fingerprint=?,identity_verified_at=?,last_error=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');
            try{$statement->execute([$row['provider_user_id'],$row['token_fingerprint'],$row['identity_verified_at'],$adminId,$id]);}catch(PDOException $error){$this->duplicate($error);}
            $this->audit($adminId,'line.oa_tested',$id,['ready'=>true]);
            return ['id'=>$id,'ready'=>true,'account'=>$this->get($id)];
        }));
    }

    public function byRouteToken(string $token): array
    {
        if(preg_match('/^[0-9a-f]{48}$/D',$token)!==1)throw new HttpException(404,'ไม่พบบัญชี LINE','LINE_OA_NOT_FOUND');
        $statement=$this->app->database()->pdo()->prepare('SELECT id FROM line_official_accounts WHERE route_token=? AND enabled=1 AND deleted_at IS NULL LIMIT 1');$statement->execute([$token]);
        $id=$statement->fetchColumn();if($id===false)throw new HttpException(404,'ไม่พบบัญชี LINE','LINE_OA_NOT_FOUND');
        return $this->credentials((int)$id);
    }

    public function touchWebhook(int $id,?string $error=null): void
    {
        $this->withRegistryLock(function()use($id,$error):void{
            $safeError=$error===null?null:(preg_match('/^[A-Z0-9_]{1,100}$/D',$error)===1?$error:'LINE_WEBHOOK_ERROR');
            $statement=$this->app->database()->pdo()->prepare('UPDATE line_official_accounts SET last_seen_at=UTC_TIMESTAMP(6),last_error=? WHERE id=? AND deleted_at IS NULL');$statement->execute([$safeError,$id]);
        });
    }

    private function row(int $id,bool $lock=false,bool $includeDeleted=false): array
    {
        if($id<0)throw new HttpException(404,'ไม่พบบัญชี LINE','LINE_OA_NOT_FOUND');
        $statement=$this->app->database()->pdo()->prepare('SELECT * FROM line_official_accounts WHERE id=?'.($includeDeleted?'':' AND deleted_at IS NULL').($lock?' FOR UPDATE':''));$statement->execute([$id]);
        $row=$statement->fetch();if(!$row)throw new HttpException(404,'ไม่พบบัญชี LINE','LINE_OA_NOT_FOUND');return $row;
    }

    private function basicId(array $row): ?string { return (int)$row['id']===0?$this->app->settings()->value('LINE_BASIC_ID'):$row['basic_id']; }

    private function secrets(array $row): array
    {
        $id=(int)$row['id'];
        if($id===0)return ['access_token'=>(string)$this->app->settings()->value('LINE_CHANNEL_ACCESS_TOKEN',''),'channel_secret'=>(string)$this->app->settings()->value('LINE_CHANNEL_SECRET','')];
        return ['access_token'=>$row['access_token_enc']===null?'':$this->cipher->decrypt($row['access_token_enc'],'line_oa_'.$id.'_access_token'),
            'channel_secret'=>$row['channel_secret_enc']===null?'':$this->cipher->decrypt($row['channel_secret_enc'],'line_oa_'.$id.'_channel_secret')];
    }

    private function safeRow(array $row): array
    {
        $id=(int)$row['id'];$secrets=$this->secrets($row);$basicId=$this->basicId($row);$links=LineBindingService::officialAccountLinks($basicId);
        $statement=$this->app->database()->pdo()->prepare("SELECT SUM(status='pending' AND expires_at>UTC_TIMESTAMP(6)) AS pending_count,SUM(status='bound') AS bound_count FROM line_room_bindings WHERE oa_id=?");$statement->execute([$id]);$counts=$statement->fetch();
        $safe=[];foreach(['name','slug','description','channel_id','add_friend_url','deleted_at','provider_user_id','identity_verified_at','last_seen_at','last_error','created_at','updated_at']as$key)$safe[$key]=$row[$key]??null;
        $safe+=['id'=>$id,'basic_id'=>$basicId,'enabled'=>(bool)$row['enabled'],'is_default'=>(bool)$row['is_default'],'legacy_route_enabled'=>(bool)$row['legacy_route_enabled'],
            'channel_access_token_configured'=>$secrets['access_token']!=='','channel_access_token_hint'=>$this->hint($secrets['access_token']),
            'channel_secret_configured'=>$secrets['channel_secret']!=='','channel_secret_hint'=>$this->hint($secrets['channel_secret']),
            'line_add_friend_url'=>$row['add_friend_url']??$links['line_add_friend_url'],
            'line_message_base'=>$links['line_add_friend_url']===null?null:'https://line.me/R/oaMessage/'.rawurlencode((string)$basicId).'/?',
            'webhook_url'=>rtrim($this->app->config->require('APP_URL'),'/').'/api/webhooks/line/oa/'.$row['route_token'],
            'line_binding_ready'=>(bool)$row['enabled']&&$row['deleted_at']===null&&$secrets['access_token']!==''&&$secrets['channel_secret']!==''&&$links['line_add_friend_url']!==null,
            'pending_count'=>(int)($counts['pending_count']??0),'bound_count'=>(int)($counts['bound_count']??0)];
        return $safe;
    }

    private function merge(array $row,array $input): array
    {
        foreach(['slug'=>40,'name'=>120]as$field=>$max){if(array_key_exists($field,$input))$row[$field]=Validator::string($input[$field],$field,1,$max);}
        if($row['name']==='')throw new HttpException(422,'กรุณาระบุชื่อบัญชี LINE','VALIDATION_ERROR',['field'=>'name']);
        if(preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$row['slug'])!==1)throw new HttpException(422,'รหัสบัญชีใช้ตัวพิมพ์เล็ก ตัวเลข ขีดกลางหรือขีดล่างเท่านั้น','VALIDATION_ERROR',['field'=>'slug']);
        foreach(['description'=>500,'basic_id'=>33,'channel_id'=>60,'add_friend_url'=>255]as$field=>$max){if(array_key_exists($field,$input))$row[$field]=$input[$field]===null||$input[$field]===''?null:Validator::string($input[$field],$field,1,$max);}
        if($row['basic_id']!==null&&preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$row['basic_id'])!==1)throw new HttpException(422,'Basic ID ต้องขึ้นต้นด้วย @','VALIDATION_ERROR',['field'=>'basic_id']);
        if($row['channel_id']!==null&&preg_match('/^[0-9]{1,60}$/D',$row['channel_id'])!==1)throw new HttpException(422,'Channel ID ต้องเป็นตัวเลข','VALIDATION_ERROR',['field'=>'channel_id']);
        if($row['add_friend_url']!==null&&preg_match('~^https://(?:line\.me/R/ti/p/(?:@|%40)[A-Za-z0-9._-]{1,32}|lin\.ee/[A-Za-z0-9_-]{1,80})$~D',$row['add_friend_url'])!==1)throw new HttpException(422,'ลิงก์เพิ่มเพื่อนต้องเป็น URL ทางการของ LINE','VALIDATION_ERROR',['field'=>'add_friend_url']);
        if(array_key_exists('enabled',$input))$row['enabled']=(int)Validator::boolean($input['enabled'],'enabled');
        foreach(['channel_access_token'=>'access_token_enc','channel_secret'=>'channel_secret_enc']as$field=>$column){
            $clear=array_key_exists($field.'_clear',$input)?Validator::boolean($input[$field.'_clear'],$field.'_clear'):false;
            $plain=$input[$field]??'';if(!is_string($plain))throw new HttpException(422,'ค่า credential ต้องเป็นข้อความ','VALIDATION_ERROR',['field'=>$field]);$plain=trim($plain);
            if($clear&&$plain!=='')throw new HttpException(422,'ระบุและลบ credential พร้อมกันไม่ได้','VALIDATION_ERROR',['field'=>$field]);
            if($plain!==''&&(strlen($plain)>8192||preg_match('/^[\x21-\x7E]+$/D',$plain)!==1))throw new HttpException(422,'credential ต้องมีเฉพาะอักขระ ASCII ที่มองเห็นได้','VALIDATION_ERROR',['field'=>$field]);
            if((int)$row['id']>0){if($clear)$row[$column]=null;elseif($plain!=='')$row[$column]=$this->cipher->encrypt($plain,'line_oa_'.$row['id'].'_'.($field==='channel_access_token'?'access_token':'channel_secret'));}
        }
        if((int)$row['id']===0)$row['basic_id']=null;
        return $row;
    }

    private function verifyIdentity(array $row): array
    {
        $secret=$this->secrets($row);
        if($secret['access_token']==='')throw new HttpException(422,'กรุณาระบุ Channel access token ก่อนทดสอบหรือเปิดใช้งาน','LINE_NOT_CONFIGURED');
        if((bool)$row['enabled']&&($secret['channel_secret']===''||$this->basicId($row)===null))throw new HttpException(422,'กรุณาตั้งค่า Basic ID และ Channel secret ให้ครบก่อนเปิดใช้งาน','LINE_NOT_CONFIGURED');
        $identity=$this->identityTransport!==null?($this->identityTransport)($secret['access_token']):$this->fetchIdentity($secret['access_token']);
        if(!is_array($identity)||!is_string($identity['userId']??null)||preg_match('/^U[0-9a-f]{32}$/D',$identity['userId'])!==1||!is_string($identity['basicId']??null)||preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$identity['basicId'])!==1)throw new HttpException(502,'LINE ส่งข้อมูลบัญชีที่ไม่ถูกต้อง','LINE_TEST_FAILED');
        if($row['provider_user_id']!==null&&!hash_equals((string)$row['provider_user_id'],$identity['userId']))throw new HttpException(409,'Token นี้เป็นของ OA อื่น กรุณาเพิ่มบัญชีใหม่เพื่อรักษาปลายทางเดิม','LINE_OA_IDENTITY_MISMATCH');
        $basic=$this->basicId($row);if($basic!==null&&!hash_equals($basic,$identity['basicId']))throw new HttpException(409,'Basic ID ไม่ตรงกับ Channel access token','LINE_OA_IDENTITY_MISMATCH');
        $row['provider_user_id']=$identity['userId'];$row['token_fingerprint']=hash_hmac('sha256','line-oa-token\0'.$row['id'].'\0'.$secret['access_token'],$this->app->config->appKey());
        $row['identity_verified_at']=gmdate('Y-m-d H:i:s');$row['last_error']=null;return $row;
    }

    private function fetchIdentity(string $token): array
    {
        $handle=curl_init('https://api.line.me/v2/bot/info');if($handle===false)throw new \RuntimeException('Cannot initialize LINE identity check');
        $body='';$tooLarge=false;
        curl_setopt_array($handle,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{if(strlen($body)+strlen($chunk)>65536){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if($ok===false||$tooLarge)throw new HttpException(502,'ติดต่อ LINE ไม่สำเร็จ กรุณาลองใหม่','LINE_TEST_FAILED');
        if($status!==200)throw new HttpException(422,'LINE ปฏิเสธ Channel access token (HTTP '.$status.')','LINE_TEST_FAILED');
        try{$value=json_decode($body,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpException(502,'LINE ส่งข้อมูลที่อ่านไม่ได้','LINE_TEST_FAILED');}
        if(!is_array($value))throw new HttpException(502,'LINE ส่งข้อมูลที่ไม่ถูกต้อง','LINE_TEST_FAILED');return $value;
    }

    private function audit(int $adminId,string $action,int $id,array $details): void
    {
        $request=new Request('POST','/internal/line/oa',[],[],[],[],[],'line-oa-'.bin2hex(random_bytes(8)));
        $this->app->audit()->writeStrict($request,['type'=>'admin','id'=>$adminId],$action,'line_official_account',$id,$details);
    }
    private function adminId(int $id): void { if($id<1)throw new HttpException(401,'กรุณาเข้าสู่ระบบผู้ดูแล','UNAUTHORIZED'); }
    private function hint(string $value): ?string { return $value===''?null:'********'.(strlen($value)>4?substr($value,-4):''); }
    private function duplicate(PDOException $error): never
    {
        foreach(['uq_line_official_accounts_slug','uq_line_official_accounts_provider']as$index)if(MySqlError::isDuplicateKey($error,$index))throw new HttpException(409,'รหัสบัญชีหรือ OA นี้มีอยู่ในระบบแล้ว','LINE_OA_DUPLICATE');
        throw $error;
    }
}
