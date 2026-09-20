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

/** One bot per dormitory. OA 0 keeps existing resident bindings and credentials. */
final class LineOfficialAccountService
{
    // Identity, labels and links are provider-derived; clients cannot override them.
    private const FIELDS = ['channel_access_token','channel_access_token_clear','channel_secret','channel_secret_clear','enabled'];
    private readonly SecretCipher $cipher;
    private int $registryDepth = 0;

    public function __construct(private readonly Application $app, private readonly ?\Closure $identityTransport = null, private readonly ?\Closure $webhookTransport = null)
    {
        if (($identityTransport !== null || $webhookTransport !== null) && (PHP_SAPI !== 'cli' || $app->config->get('APP_ENV') !== 'testing')) {
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

    /** Keep setup writes bounded; do not let generic transaction retries multiply a network check. */
    private function withSetupTransaction(callable $callback): array
    {
        $pdo = $this->app->database()->pdo();
        $previous = (int)$pdo->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout=3');
        try {
            return $this->withRegistryLock(fn():array=>$this->app->database()->transaction(function(PDO $pdo) use ($callback):array {
                try { return $callback($pdo); }
                catch (PDOException $error) {
                    if (in_array((int)($error->errorInfo[1] ?? 0), [1205,1213], true)) {
                        throw new HttpException(503, 'มีงาน LINE กำลังบันทึกข้อมูลอยู่ กรุณารอสักครู่แล้วลองใหม่', 'LINE_REGISTRY_BUSY');
                    }
                    throw $error;
                }
            }), 2);
        } finally { $pdo->exec('SET SESSION innodb_lock_wait_timeout='.max(1,$previous)); }
    }

    public function all(): array
    {
        return [$this->get(0)];
    }

    public function get(int $id): array { return $this->safeRow($this->row($id,false,true)); }

    /** Internal only: never serialize this return value into a response or audit. */
    public function credentials(int $id): array
    {
        $this->assertBotId($id);
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
        $id=$this->app->database()->pdo()->query('SELECT id FROM line_official_accounts WHERE id=0 AND enabled=1 AND deleted_at IS NULL')->fetchColumn();
        if ($id===false) throw new HttpException(409,'ยังไม่ได้เลือกบัญชี LINE เริ่มต้นที่เปิดใช้งาน','LINE_DEFAULT_NOT_CONFIGURED');
        return (int)$id;
    }

    public function create(array $input,int $adminId): array
    {
        $this->adminId($adminId);
        throw new HttpException(409,'หอพักใช้ LINE Bot ได้เพียงบัญชีเดียว กรุณาแก้ไขบอทของหอพัก','LINE_SINGLE_BOT_ONLY');
    }

    public function update(int $id,array $input,int $adminId): array
    {
        $this->assertBotId($id);
        Validator::only($input,self::FIELDS); $this->adminId($adminId);
        return $this->withSetupTransaction(function(PDO $pdo)use($id,$input,$adminId):array{
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
            $changedSecret=$oldSecrets['channel_secret']!==$newSecrets['channel_secret'];
            $identityChanged=$changedToken || $oldBasicId!==$this->basicId($row) || $old['channel_id']!==$row['channel_id'];
            $needsDiscovery=$newSecrets['access_token']!==''&&($row['name']===''||$row['slug']===''||$this->basicId($row)===null);
            $readinessChanged=$identityChanged||!(bool)$old['enabled']||$changedSecret;
            if((bool)$row['enabled']&&($newSecrets['access_token']===''||$newSecrets['channel_secret']===''))throw new HttpException(422,'กรุณาตั้งค่า Channel secret ให้ครบก่อนเปิดใช้งาน','LINE_NOT_CONFIGURED');
            if((bool)$row['enabled'] && ($identityChanged || !(bool)$old['enabled'] || $needsDiscovery || $newSecrets['access_token']!==''))$row=$this->verifyIdentity($row,$adminId);
            elseif($identityChanged){$row['token_fingerprint']=null;$row['identity_verified_at']=null;}
            if($changedSecret){$row['last_seen_at']=null;$row['last_error']=null;}
            $this->assertMetadata($row);
            if(!(bool)$row['enabled'])$row['is_default']=0;
            $columns=['slug','name','description','basic_id','channel_id','add_friend_url','access_token_enc','channel_secret_enc','enabled','is_default','provider_user_id','token_fingerprint','identity_verified_at','last_seen_at','last_error'];
            $statement=$pdo->prepare('UPDATE line_official_accounts SET '.implode(',',array_map(static fn(string $key)=>$key.'=?',$columns)).',updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND deleted_at IS NULL');
            try{$statement->execute([...array_map(static fn(string $key)=>$row[$key],$columns),$adminId,$id]);}catch(PDOException $error){$this->duplicate($error);}
            $this->audit($adminId,'line.oa_updated',$id,['changed_fields'=>array_keys($input),'enabled'=>(bool)$row['enabled']]);
            return $this->get($id);
        });
    }

    public function remove(int $id,int $adminId): array
    {
        $this->adminId($adminId);
        throw new HttpException(409,'ไม่สามารถลบบอทของหอพักได้ กรุณาปิดใช้งานหากต้องการหยุดรับส่ง','LINE_SINGLE_BOT_ONLY');
    }

    public function setDefault(int $id,int $adminId): array
    {
        $this->assertBotId($id);
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
        $this->assertBotId($id);
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
        $this->assertBotId($id);
        $this->adminId($adminId);
        return $this->withSetupTransaction(function(PDO $pdo)use($id,$adminId):array{
            $row=$this->verifyIdentity($this->row($id,true),$adminId);
            $statement=$pdo->prepare('UPDATE line_official_accounts SET name=?,slug=?,basic_id=?,provider_user_id=?,token_fingerprint=?,identity_verified_at=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?');
            try{$statement->execute([$row['name'],$row['slug'],$row['basic_id'],$row['provider_user_id'],$row['token_fingerprint'],$row['identity_verified_at'],$adminId,$id]);}catch(PDOException $error){$this->duplicate($error);}
            $account=$this->get($id);
            $this->audit($adminId,'line.oa_tested',$id,['identity_verified'=>true,'ready'=>$account['operational_ready']]);
            return ['id'=>$id,'identity_verified'=>true,'ready'=>$account['operational_ready'],'account'=>$account];
        });
    }

    /** Live check: remote configuration reads run AFTER the identity transaction releases its locks. */
    public function checkConnection(int $id, int $adminId): array
    {
        $tested = $this->test($id, $adminId);
        $row = $this->row($id);
        $secrets = $this->secrets($row);
        $stamp = $this->connectionStamp($row, $secrets);
        $account = $this->get($id);
        $remote = null; $failure = null;
        if ($account['enabled']) {
            try {
                $remote = $this->webhookTransport !== null
                    ? ($this->webhookTransport)($secrets['access_token'])
                    : $this->requestLine('/v2/bot/channel/webhook/endpoint', $secrets['access_token']);
                if (!is_array($remote) || !is_string($remote['endpoint'] ?? null) || !is_bool($remote['active'] ?? null)) {
                    throw new HttpException(502, 'LINE ส่งสถานะ Webhook ที่อ่านไม่ได้ กรุณาตรวจอีกครั้ง', 'LINE_RESPONSE_INVALID');
                }
            } catch (HttpException $error) { $failure = $error; $remote = null; }
        }
        $latest = $this->row($id);
        if (!hash_equals($stamp, $this->connectionStamp($latest, $this->secrets($latest)))) {
            throw new HttpException(409, 'ค่าบอทถูกเปลี่ยนระหว่างตรวจสอบ กรุณาตรวจสถานะใหม่', 'LINE_CONFIGURATION_CHANGED');
        }
        $account = $this->get($id);
        $connection = self::connectionState($account, $remote);
        if ($failure !== null) $connection = array_replace($connection, ['ready'=>false, 'status'=>$failure->errorCode, 'message'=>$failure->getMessage()]);
        $connection['checked_at'] = gmdate('c');
        return ['id'=>$id, 'identity_verified'=>$account['identity_verified'], 'ready'=>$connection['ready'], 'account'=>$account, 'connection'=>$connection];
    }

    private function connectionStamp(array $row, array $secrets): string
    {
        return hash_hmac('sha256', json_encode([$row['enabled'], $row['deleted_at'], $row['route_token'], $row['legacy_route_enabled'], $row['provider_user_id'], $secrets], JSON_THROW_ON_ERROR), $this->app->config->appKey());
    }

    private static function connectionState(array $account, ?array $remote): array
    {
        $expected = (string)($account['webhook_url'] ?? '');
        $legacy = preg_replace('~/oa/[a-f0-9]{48}$~D', '', $expected);
        $matches = $remote !== null && ($remote['endpoint'] === $expected
            || (($account['legacy_route_enabled'] ?? false) && $remote['endpoint'] === $legacy));
        $result = ['ready'=>false, 'endpoint_matches'=>$remote === null ? null : $matches, 'webhook_active'=>$remote['active'] ?? null];
        [$status, $message] = match (true) {
            !($account['enabled'] ?? false) => ['disabled', 'บอทถูกปิดใช้งาน เปิดใช้งานก่อนรับส่งข้อความ'],
            !($account['credentials_ready'] ?? false) => ['missing_credentials', 'กรอก Token และ Secret ของ Messaging API บัญชีเดียวกันให้ครบ'],
            !($account['identity_verified'] ?? false) => ['identity_unverified', 'ยังไม่ยืนยัน Token ของค่าปัจจุบัน กรุณาตรวจการเชื่อมต่อใหม่'],
            !str_starts_with($expected, 'https://') => ['public_url_invalid', 'Webhook ต้องเป็นเว็บไซต์ HTTPS ที่ LINE เข้าถึงได้ ตรวจ APP_URL บนโฮสต์ก่อน'],
            $remote === null => ['unchecked', 'ยังอ่านการตั้งค่า Webhook จาก LINE ไม่สำเร็จ กรุณาตรวจอีกครั้ง'],
            !$matches => ['endpoint_mismatch', 'Webhook URL ที่ LINE ไม่ตรงกับระบบ คัดลอก URL ด้านล่างไปวางใน LINE Developers แล้วกด Verify'],
            $remote['active'] !== true => ['webhook_disabled', 'URL ถูกต้อง แต่ยังปิด Use webhook อยู่ เปิด Use webhook ใน LINE Developers'],
            !empty($account['last_error']) => ['callback_error', 'Webhook ล่าสุดมีข้อผิดพลาด ตรวจ Channel secret ของบอทนี้แล้วกด Verify ใหม่'],
            !($account['webhook_verified'] ?? false) => ['awaiting_callback', 'Token และ URL ถูกต้อง เปิด Use webhook แล้ว แต่ยังไม่เคยรับลายเซ็นที่ถูกต้อง กด Verify ใน LINE Developers'],
            default => ['ready', 'Token ถูกต้อง URL ตรง เปิด Use webhook และเคยรับลายเซ็นถูกต้องแล้ว ทดลองพิมพ์ “เมนู” ในแชตเพื่อยืนยันการตอบจริง'],
        };
        $result['ready'] = $status === 'ready';
        return $result + ['status'=>$status, 'message'=>$message];
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
        $credentialsReady=$id===0&&(bool)$row['enabled']&&$row['deleted_at']===null&&$secrets['access_token']!==''&&$secrets['channel_secret']!==''&&$links['line_add_friend_url']!==null;
        $identityVerified=is_string($row['provider_user_id'])&&is_string($row['identity_verified_at'])
            &&is_string($row['token_fingerprint'])&&hash_equals($row['token_fingerprint'],hash_hmac('sha256','line-oa-token\0'.$id.'\0'.$secrets['access_token'],$this->app->config->appKey()));
        $webhookVerified=$credentialsReady&&$row['last_seen_at']!==null&&$row['last_error']===null;
        $safe=[];foreach(['name','slug','description','channel_id','add_friend_url','deleted_at','provider_user_id','identity_verified_at','last_seen_at','last_error','created_at','updated_at']as$key)$safe[$key]=$row[$key]??null;
        $safe+=['id'=>$id,'basic_id'=>$basicId,'enabled'=>(bool)$row['enabled'],'is_default'=>$id===0&&(bool)$row['enabled'],'legacy_route_enabled'=>(bool)$row['legacy_route_enabled'],
            'channel_access_token_configured'=>$secrets['access_token']!=='','channel_access_token_hint'=>$this->hint($secrets['access_token']),
            'channel_secret_configured'=>$secrets['channel_secret']!=='','channel_secret_hint'=>$this->hint($secrets['channel_secret']),
            'line_add_friend_url'=>$links['line_add_friend_url'],
            'line_message_base'=>$links['line_add_friend_url']===null?null:'https://line.me/R/oaMessage/'.rawurlencode((string)$basicId).'/?',
            'webhook_url'=>rtrim($this->app->config->require('APP_URL'),'/').'/api/webhooks/line/oa/'.$row['route_token'],
            'credentials_ready'=>$credentialsReady,'identity_verified'=>$identityVerified,'webhook_verified'=>$webhookVerified,'operational_ready'=>$identityVerified&&$webhookVerified,
            'line_binding_ready'=>$credentialsReady,
            'pending_count'=>(int)($counts['pending_count']??0),'bound_count'=>(int)($counts['bound_count']??0)];
        return $safe;
    }

    private function merge(array $row,array $input): array
    {
        foreach(['slug'=>40,'name'=>120]as$field=>$max){
            if(!array_key_exists($field,$input))continue;
            if(!is_string($input[$field]))throw new HttpException(422,"{$field} ไม่ถูกต้อง",'VALIDATION_ERROR',['field'=>$field]);
            $row[$field]=trim($input[$field])===''?'':Validator::string($input[$field],$field,1,$max);
        }
        if($row['slug']!==''&&preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',$row['slug'])!==1)throw new HttpException(422,'รหัสบัญชีใช้ตัวพิมพ์เล็ก ตัวเลข ขีดกลางหรือขีดล่างเท่านั้น','VALIDATION_ERROR',['field'=>'slug']);
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

    private function verifyIdentity(array $row,?int $adminId=null): array
    {
        $secret=$this->secrets($row);
        if($secret['access_token']==='')throw new HttpException(422,'กรุณาระบุ Channel access token ก่อนทดสอบหรือเปิดใช้งาน','LINE_NOT_CONFIGURED');
        if((bool)$row['enabled']&&$secret['channel_secret']==='')throw new HttpException(422,'กรุณาตั้งค่า Channel secret ให้ครบก่อนเปิดใช้งาน','LINE_NOT_CONFIGURED');
        $identity=$this->identityTransport!==null?($this->identityTransport)($secret['access_token']):$this->fetchIdentity($secret['access_token']);
        if(!is_array($identity)||!is_string($identity['userId']??null)||preg_match('/^U[0-9a-f]{32}$/D',$identity['userId'])!==1||!is_string($identity['basicId']??null)||preg_match('/^@[A-Za-z0-9._-]{1,32}$/D',$identity['basicId'])!==1)throw new HttpException(502,'LINE ส่งข้อมูลบัญชีที่ไม่ถูกต้อง','LINE_TEST_FAILED');
        if($row['provider_user_id']!==null&&!hash_equals((string)$row['provider_user_id'],$identity['userId']))throw new HttpException(409,'Token ต้องเป็นของ LINE Bot เดิมของหอพัก เพื่อรักษาการผูกบัญชีผู้พัก','LINE_OA_IDENTITY_MISMATCH');
        if((int)$row['id']===0){
            if($this->basicId($row)!==$identity['basicId']){
                if($row['provider_user_id']===null&&$this->basicId($row)!==null&&$this->hasLegacyRecipients()){
                    throw new HttpException(409,'บัญชีเดิมมีผู้รับที่ผูกไว้และ Basic ID ไม่ตรงกับ Token ต้องตรวจสอบบัญชีเดิมก่อนเปลี่ยน','LINE_OA_IDENTITY_MISMATCH');
                }
                if($adminId===null)throw new \RuntimeException('Admin identity is required to discover the legacy LINE Basic ID');
                $this->app->settings()->update(['line_basic_id'=>$identity['basicId']],$adminId);
            }
            $row['name']=$this->identityName($identity);
            if($row['slug']==='')$row['slug']='legacy';
        }else{
            if($row['basic_id']===null)$row['basic_id']=$identity['basicId'];
            if($row['name']==='')$row['name']=$this->identityName($identity);
            if($row['slug']==='')$row['slug']=$this->identitySlug($identity['basicId'],$identity['userId']);
        }
        $basic=$this->basicId($row);if($basic!==null&&!hash_equals($basic,$identity['basicId']))throw new HttpException(409,'Basic ID ไม่ตรงกับ Channel access token','LINE_OA_IDENTITY_MISMATCH');
        $row['provider_user_id']=$identity['userId'];$row['token_fingerprint']=hash_hmac('sha256','line-oa-token\0'.$row['id'].'\0'.$secret['access_token'],$this->app->config->appKey());
        $row['identity_verified_at']=gmdate('Y-m-d H:i:s');return $row;
    }

    private function assertMetadata(array $row): void
    {
        if($row['name']==='')throw new HttpException(422,'กรุณาระบุชื่อบัญชี LINE หรือเปิดใช้งานเพื่อให้ระบบดึงชื่ออัตโนมัติ','VALIDATION_ERROR',['field'=>'name']);
        if(preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/D',(string)$row['slug'])!==1)throw new HttpException(422,'กรุณาระบุรหัสบัญชี หรือเปิดใช้งานเพื่อให้ระบบสร้างให้อัตโนมัติ','VALIDATION_ERROR',['field'=>'slug']);
    }

    private function hasLegacyRecipients(): bool
    {
        return (bool)$this->app->database()->pdo()->query("SELECT
          EXISTS(SELECT 1 FROM residents WHERE line_user_id IS NOT NULL)
          OR EXISTS(SELECT 1 FROM line_room_bindings WHERE oa_id=0 AND status IN ('pending','bound'))
          OR EXISTS(SELECT 1 FROM line_link_codes WHERE status IN ('pending','bound'))
          OR EXISTS(SELECT 1 FROM line_admin_recipients WHERE oa_id=0 AND revoked_at IS NULL)")->fetchColumn();
    }

    private function identityName(array $identity): string
    {
        $display=$identity['displayName']??null;
        if(is_string($display)){
            $display=trim($display);$length=function_exists('mb_strlen')?mb_strlen($display,'UTF-8'):strlen($display);
            if($length>=1&&$length<=120)return $display;
        }
        return 'LINE OA '.(string)$identity['basicId'];
    }

    private function identitySlug(string $basicId,string $providerUserId): string
    {
        $base=strtolower(ltrim($basicId,'@'));
        $base=preg_replace('/[^a-z0-9_-]+/','-',$base)??'';$base=trim($base,'-_');
        if($base==='')$base='account';
        $base=substr($base,0,26);
        return 'line-'.$base.'-'.substr($providerUserId,-8);
    }

    private function readyDefaultExists(): bool
    {
        $row=$this->app->database()->pdo()->query('SELECT * FROM line_official_accounts WHERE is_default=1 AND enabled=1 AND deleted_at IS NULL LIMIT 1')->fetch();
        return is_array($row)&&($this->safeRow($row)['line_binding_ready']??false)===true;
    }

    private function fetchIdentity(string $token): array
    {
        return $this->requestLine('/v2/bot/info', $token);
    }

    private function requestLine(string $path, string $token): array
    {
        if (!in_array($path, ['/v2/bot/info', '/v2/bot/channel/webhook/endpoint'], true)) throw new \InvalidArgumentException('Unsupported LINE setup endpoint');
        if (!function_exists('curl_init')) throw new HttpException(503, 'โฮสต์ยังไม่ได้เปิด PHP cURL กรุณาเปิดส่วนขยายก่อนเชื่อมต่อ LINE', 'LINE_CURL_MISSING');
        $handle = curl_init('https://api.line.me'.$path);
        if ($handle === false) throw new HttpException(503, 'เริ่มเชื่อมต่อ LINE ไม่สำเร็จ กรุณาลองใหม่', 'LINE_CONNECT_FAILED');
        $body = ''; $tooLarge = false;
        curl_setopt_array($handle, [CURLOPT_HTTPGET=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8, CURLOPT_MAXREDIRS=>0, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_NOSIGNAL=>true,
            CURLOPT_WRITEFUNCTION=>static function($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body)+strlen($chunk)>65536) { $tooLarge=true; return 0; } $body.=$chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $errno = curl_errno($handle); curl_close($handle);
        self::assertSetupResponse($status, $ok === false ? $errno : 0, $tooLarge, $path);
        try { $value=json_decode($body,true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(502, 'LINE ส่งข้อมูลที่อ่านไม่ได้ กรุณาตรวจใหม่', 'LINE_RESPONSE_INVALID'); }
        if (!is_array($value)) throw new HttpException(502, 'LINE ส่งข้อมูลที่ไม่ถูกต้อง กรุณาตรวจใหม่', 'LINE_RESPONSE_INVALID');
        return $value;
    }

    /** Translate only numeric status/errno; never expose provider bodies, headers or curl_error. */
    private static function assertSetupResponse(int $status, int $errno, bool $tooLarge, string $path): void
    {
        if ($tooLarge) throw new HttpException(502, 'ข้อมูลตอบจาก LINE เกินขอบเขตที่รองรับ', 'LINE_RESPONSE_INVALID');
        if ($errno !== 0) {
            [$code,$message] = match ($errno) {
                28 => ['LINE_CONNECT_TIMEOUT','ติดต่อ LINE เกิน 8 วินาที ตรวจเครือข่ายของโฮสต์แล้วลองใหม่'],
                6 => ['LINE_DNS_ERROR','โฮสต์หา api.line.me ไม่พบ ตรวจ DNS ของโฮสต์'],
                35, 51, 58, 60, 77, 83 => ['LINE_TLS_ERROR','โฮสต์ตรวจใบรับรอง HTTPS ของ LINE ไม่ผ่าน ตรวจ CA certificate และเวลาของเซิร์ฟเวอร์ ห้ามปิดการตรวจ SSL'],
                default => ['LINE_CONNECT_FAILED','โฮสต์เชื่อมต่อ LINE ไม่สำเร็จ ตรวจเครือข่ายขาออกแล้วลองใหม่'],
            };
            throw new HttpException(502,$message,$code);
        }
        if ($status===200) return;
        if ($status===401 || $status===403) throw new HttpException(422, 'LINE ปฏิเสธ Token ตรวจว่าเป็น Channel access token ของ Messaging API ไม่ใช่ Channel secret และยังไม่ถูกยกเลิก', 'LINE_TOKEN_REJECTED');
        if ($status===404 && $path==='/v2/bot/channel/webhook/endpoint') throw new HttpException(409, 'ยังไม่ได้ตั้ง Webhook URL ที่ LINE คัดลอก URL ของระบบไปวางใน LINE Developers', 'LINE_WEBHOOK_NOT_SET');
        if ($status===429) throw new HttpException(429, 'เรียกตรวจ LINE ถี่เกินไป กรุณารอสักครู่แล้วลองใหม่', 'LINE_API_RATE_LIMITED');
        throw new HttpException(502, 'LINE ยังตอบไม่สำเร็จ (HTTP '.$status.') กรุณาลองใหม่ภายหลัง', 'LINE_API_UNAVAILABLE');
    }

    private function audit(int $adminId,string $action,int $id,array $details): void
    {
        $request=new Request('POST','/internal/line/oa',[],[],[],[],[],'line-oa-'.bin2hex(random_bytes(8)));
        $this->app->audit()->writeStrict($request,['type'=>'admin','id'=>$adminId],$action,'line_official_account',$id,$details);
    }
    private function adminId(int $id): void { if($id<1)throw new HttpException(401,'กรุณาเข้าสู่ระบบผู้ดูแล','UNAUTHORIZED'); }
    public function assertBotId(int $id): void
    {
        if($id!==0)throw new HttpException(409,'หอพักใช้ LINE Bot ได้เพียงบัญชีเดียว กรุณาใช้บอทของหอพัก','LINE_SINGLE_BOT_ONLY');
    }
    private function hint(string $value): ?string { return $value===''?null:'********'.(strlen($value)>4?substr($value,-4):''); }
    private function duplicate(PDOException $error): never
    {
        foreach(['uq_line_official_accounts_slug','uq_line_official_accounts_provider']as$index)if(MySqlError::isDuplicateKey($error,$index))throw new HttpException(409,'รหัสบัญชีหรือ OA นี้มีอยู่ในระบบแล้ว','LINE_OA_DUPLICATE');
        throw $error;
    }
}
