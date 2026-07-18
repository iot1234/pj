<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;
use Dormitory\Support\Validator;
use PDO;

final class AuthService
{
    // Fixed hashes avoid generating an additional expensive hash on each
    // request while matching the runtime KDF used by real credentials.
    private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QTFrdlJTMlNhNVlOcVNaag$4OGNDlE1/yhI16yCneeHZrvMpjVK3wp5Z14RKNiHaws';
    private const DUMMY_BCRYPT_HASH = '$2y$10$u0R/rN94jiaDgP4CtmlUUu9M5buyoEZvxmz4wiLkxAAQqTs4/Nuki';
    private const LOGIN_DEVICE_TTL = 2592000;

    public function __construct(private readonly Application $app)
    {
    }

    /** @return array<string,mixed>|null */
    public function resolveActor(): ?array
    {
        $sessionActor = $this->app->session()->actor();
        if (!$sessionActor || !isset($sessionActor['type'], $sessionActor['id'], $sessionActor['auth_version'])) {
            return null;
        }
        $pdo = $this->app->database()->pdo();
        if ($sessionActor['type'] === 'admin') {
            $statement = $pdo->prepare('SELECT id,username,role,auth_version,active FROM admin_users WHERE id=? LIMIT 1');
            $statement->execute([(int) $sessionActor['id']]);
            $row = $statement->fetch();
            if (!$row || !(bool) $row['active'] || (int) $row['auth_version'] !== (int) $sessionActor['auth_version']) {
                $this->app->session()->revokeLocal();
                return null;
            }
            return [
                'type' => 'admin', 'id' => (int) $row['id'], 'username' => $row['username'],
                'name' => $row['username'], 'role' => $row['role'], 'auth_version' => (int) $row['auth_version'],
            ];
        }
        if ($sessionActor['type'] === 'resident') {
            $statement = $pdo->prepare(
                "SELECT r.id,r.full_name,r.phone_norm,r.email,r.line_user_id,r.auth_version,r.active,
                        o.id AS occupancy_id,rm.id AS room_id,rm.room_code
                   FROM residents r
                   JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                   JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE r.id=? AND r.active=1
                  ORDER BY o.id DESC LIMIT 2"
            );
            $statement->execute([(int) $sessionActor['id']]);
            $rows = $statement->fetchAll();
            $row = count($rows) === 1 ? $rows[0] : false;
            if (!$row || (int) $row['auth_version'] !== (int) $sessionActor['auth_version']) {
                $this->app->session()->revokeLocal();
                return null;
            }
            return [
                'type' => 'resident', 'id' => (int) $row['id'], 'full_name' => $row['full_name'],
                'name' => $row['full_name'], 'phone' => $row['phone_norm'], 'email' => $row['email'],
                'line_user_id' => $row['line_user_id'], 'auth_version' => (int) $row['auth_version'],
                'occupancy_id' => (int) $row['occupancy_id'], 'room_id' => (int) $row['room_id'],
                'room_code' => $row['room_code'], 'role' => 'resident',
                'auth_method' => 'phone_only', 'assurance' => 'low',
            ];
        }
        $this->app->session()->revokeLocal();
        return null;
    }

    /** @return array<string,mixed> */
    public function adminLogin(Request $request, array $input): array
    {
        Validator::only($input, ['username', 'password']);
        $username = strtolower(trim(is_string($input['username'] ?? null) ? $input['username'] : ''));
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $wellFormed = (bool) preg_match('/^[a-z0-9_.-]{3,64}$/', $username) && strlen($password) <= 200;
        $ip = $this->app->security()->clientIp($request);
        $this->app->limiter()->hit('admin-login-ip', $ip, 20, 900, 900);

        $row = false;
        if ($wellFormed) {
            $statement = $this->app->database()->pdo()->prepare('SELECT id,username,password_hash,role,auth_version,active FROM admin_users WHERE username=? LIMIT 1');
            $statement->execute([$username]);
            $row = $statement->fetch();
        }
        // Known principals get a finite per-account bucket. Unknown and
        // malformed principals collapse into one bucket per source IP so an
        // attacker cannot grow the table with arbitrary usernames. A blocked
        // account is normalized to the same credential response below, which
        // prevents using bucket behavior as an account-enumeration oracle.
        $accountIdentity=$row?(string)$row['username']:'unknown:'.$ip;
        $sourceIdentity=$accountIdentity.':'.$ip;
        $sourceAllowed=$this->accountAttemptAllowed('admin-login-account-source',$sourceIdentity,5,900,1800);
        $globalAllowed=$this->globalAccountAttemptAllowed('admin-login-account',$accountIdentity,$sourceAllowed,$ip,50,900,1800);
        $accountAllowed=$sourceAllowed&&$globalAllowed;
        $valid = self::verifyCredential($password,is_string($row['password_hash']??null)?(string)$row['password_hash']:null);
        $trustedDevice=$row&&$valid&&$this->hasTrustedLoginDevice('admin',(int)$row['id'],(int)$row['auth_version']);
        usleep(random_int(180000, 320000));
        if ((!$accountAllowed&&!$trustedDevice) || !$row || !(bool) $row['active'] || !$valid) {
            $this->app->audit()->write($request, null, 'auth.admin_failed', 'admin_user', null, [
                'principal_hash' => hash_hmac('sha256', $username, $this->app->config->appKey()),
                'account_rate_limited'=>!$accountAllowed,
            ]);
            throw new HttpException(401, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'INVALID_CREDENTIALS');
        }

        if (Password::needsRehash((string) $row['password_hash'])) {
            $newHash = Password::hash($password);
            $rehash = $this->app->database()->pdo()->prepare(
                'UPDATE admin_users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND password_hash=? AND auth_version=?'
            );
            $rehash->execute([$newHash, $row['id'], $row['password_hash'], $row['auth_version']]);
            if ($rehash->rowCount() !== 1) {
                $this->app->audit()->write($request, null, 'auth.admin_stale_login', 'admin_user', $row['id']);
                throw new HttpException(401, 'Username or password is invalid', 'INVALID_CREDENTIALS');
            }
            $row['password_hash'] = $newHash;
        }
        $actor = [
            'type' => 'admin', 'id' => (int) $row['id'], 'username' => $row['username'],
            'name' => $row['username'], 'role' => $row['role'], 'auth_version' => (int) $row['auth_version'],
        ];
        $this->app->session()->login($actor);
        $this->app->clearActorCache();
        $this->app->limiter()->clear('admin-login-account', $accountIdentity);
        $this->app->limiter()->clear('admin-login-account-source', $sourceIdentity);
        $this->rememberLoginDevice('admin',(int)$row['id'],(int)$row['auth_version']);
        $this->app->audit()->write($request, $actor, 'auth.admin_login', 'admin_user', $row['id']);
        return $actor;
    }

    /** @return array<string,mixed> */
    public function residentLogin(Request $request, array $input): array
    {
        Validator::only($input, ['phone']);
        $rawPhone = is_string($input['phone'] ?? null) && strlen($input['phone']) <= 32
            ? $input['phone']
            : '';
        try { $phone = Validator::phone($rawPhone); $phoneValid = $rawPhone !== ''; }
        catch (HttpException) { $phone = ''; $phoneValid = false; }
        $ip = $this->app->security()->clientIp($request);
        $this->app->limiter()->hit('resident-login-ip', $ip, 12, 900, 900);
        $this->app->limiter()->hit('resident-login-ip-daily', $ip, 100, 86400, 3600);

        $row = false;
        if ($phoneValid) {
            $statement = $this->app->database()->pdo()->prepare(
                "SELECT r.id,r.full_name,r.phone_norm,r.email,r.auth_version,
                        o.id AS occupancy_id,o.room_id,rm.room_code
                   FROM residents r
                   JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                   JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE r.phone_norm=? AND r.active=1
                  ORDER BY o.id DESC LIMIT 2"
            );
            $statement->execute([$phone]);
            $rows = $statement->fetchAll();
            $row = count($rows) === 1 ? $rows[0] : false;
        }
        $accountIdentity=$row?(string)$row['phone_norm']:'unknown:'.$ip;
        $sourceIdentity=$accountIdentity.':'.$ip;
        $sourceAllowed=$this->accountAttemptAllowed('resident-login-account-source',$sourceIdentity,8,900,1800);
        $globalAllowed=$this->globalAccountAttemptAllowed('resident-login-account',$accountIdentity,$sourceAllowed,$ip,30,86400,3600);
        $accountAllowed=$sourceAllowed&&$globalAllowed;
        usleep(random_int(180000, 320000));
        if (!$accountAllowed || !$row) {
            $this->app->audit()->write($request, null, 'auth.resident_failed', 'resident', null, [
                'principal_hash' => hash_hmac('sha256', $phoneValid?$phone:trim($rawPhone), $this->app->config->appKey()),
                'account_rate_limited'=>!$accountAllowed,
            ]);
            throw new HttpException(401, 'ไม่สามารถเข้าสู่ระบบด้วยเบอร์นี้ได้', 'INVALID_CREDENTIALS');
        }
        $actor = [
            'type' => 'resident', 'id' => (int) $row['id'], 'full_name' => $row['full_name'],
            'name' => $row['full_name'], 'phone' => $row['phone_norm'], 'email' => $row['email'],
            'auth_version' => (int) $row['auth_version'], 'occupancy_id' => (int) $row['occupancy_id'],
            'room_id' => (int) $row['room_id'], 'room_code' => $row['room_code'], 'role' => 'resident',
            'auth_method' => 'phone_only', 'assurance' => 'low',
        ];
        $this->app->audit()->writeStrict($request, $actor, 'auth.resident_login', 'resident', $row['id'], [
            'auth_method'=>'phone_only', 'assurance'=>'low',
        ]);
        $this->app->session()->login($actor);
        $this->app->clearActorCache();
        return $actor;
    }

    public function logout(Request $request): void
    {
        $actor = $this->app->actor();
        if ($actor) {
            $this->app->audit()->write($request, $actor, 'auth.logout', $actor['type'] . '_user', $actor['id']);
        }
        $this->app->session()->logout();
        $this->app->clearActorCache();
    }

    private function accountAttemptAllowed(string $scope,string $identity,int $max,int $windowSeconds,int $blockSeconds): bool
    {
        try{
            $this->app->limiter()->hit($scope,$identity,$max,$windowSeconds,$blockSeconds);
            return true;
        }catch(HttpException $error){
            if($error->errorCode==='RATE_LIMITED')return false;
            throw $error;
        }
    }

    private function globalAccountAttemptAllowed(string $scope,string $identity,bool $sourceAllowed,string $ip,int $max,int $windowSeconds,int $blockSeconds): bool
    {
        if($sourceAllowed)return $this->accountAttemptAllowed($scope,$identity,$max,$windowSeconds,$blockSeconds);
        // Preserve the second DB transaction without incrementing the real
        // account bucket after this source is already blocked. The IP bucket
        // caps this padding bucket far below its maximum.
        $this->accountAttemptAllowed($scope.'-timing-pad',$ip,1000,$windowSeconds,$blockSeconds);
        return false;
    }

    private static function verifyCredential(string $plain,?string $hash): bool
    {
        if(!defined('PASSWORD_ARGON2ID'))return Password::verify($plain,$hash??self::DUMMY_BCRYPT_HASH);
        // Match one Argon2id plus one bcrypt verification for current,
        // legacy, and unknown credentials. This closes the KDF/account timing
        // distinction while retaining a fixed non-user dummy hash.
        if(is_string($hash)&&str_starts_with($hash,'$argon2')){
            $valid=Password::verify($plain,$hash);
            Password::verify($plain,self::DUMMY_BCRYPT_HASH);
            return $valid;
        }
        Password::verify($plain,self::DUMMY_ARGON2ID_HASH);
        $valid=Password::verify($plain,$hash??self::DUMMY_BCRYPT_HASH);
        return is_string($hash)&&$valid;
    }

    private function hasTrustedLoginDevice(string $type,int $id,int $authVersion): bool
    {
        $token=$_COOKIE[$this->loginDeviceCookieName($type,$id)]??null;
        return is_string($token)&&$this->validLoginDeviceToken($token,$type,$id,$authVersion,time());
    }

    private function rememberLoginDevice(string $type,int $id,int $authVersion): void
    {
        $expires=time()+self::LOGIN_DEVICE_TTL;
        $token=$this->createLoginDeviceToken($type,$id,$authVersion,$expires);
        $name=$this->loginDeviceCookieName($type,$id);
        if(setcookie($name,$token,[
            'expires'=>$expires,'path'=>'/','domain'=>'','secure'=>$this->app->config->requestIsHttps(),
            'httponly'=>true,'samesite'=>'Lax',
        ]))$_COOKIE[$name]=$token;
    }

    private function loginDeviceCookieName(string $type,int $id): string
    {
        if(!in_array($type,['admin','resident'],true)||$id<1)throw new \InvalidArgumentException('Invalid login device identity');
        $base='dormitory_login_device_'.$type.'_'.$id;
        return $this->app->config->requestIsHttps()?'__Host-'.$base:$base;
    }

    private function createLoginDeviceToken(string $type,int $id,int $authVersion,int $expires): string
    {
        $payload=$type.'|'.$id.'|'.$authVersion.'|'.$expires.'|'.bin2hex(random_bytes(16));
        $encoded=rtrim(strtr(base64_encode($payload),'+/','-_'),'=');
        $signature=hash_hmac('sha256',"login-device\0{$encoded}",$this->app->config->appKey());
        return $encoded.'.'.$signature;
    }

    private function validLoginDeviceToken(string $token,string $type,int $id,int $authVersion,int $now): bool
    {
        if(preg_match('/^([A-Za-z0-9_-]{20,300})\.([a-f0-9]{64})$/D',$token,$match)!==1)return false;
        $expected=hash_hmac('sha256',"login-device\0{$match[1]}",$this->app->config->appKey());
        if(!hash_equals($expected,$match[2]))return false;
        $encoded=strtr($match[1],'-_','+/');$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);
        $payload=base64_decode($encoded,true);if(!is_string($payload))return false;
        $parts=explode('|',$payload);
        if(count($parts)!==5||!ctype_digit($parts[1])||!ctype_digit($parts[2])||!ctype_digit($parts[3])||preg_match('/^[a-f0-9]{32}$/D',$parts[4])!==1)return false;
        $expires=(int)$parts[3];
        return hash_equals($type,$parts[0])&&(int)$parts[1]===$id&&(int)$parts[2]===$authVersion
            &&$expires>=$now&&$expires<=$now+self::LOGIN_DEVICE_TTL+300;
    }
}
