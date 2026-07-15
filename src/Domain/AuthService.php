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
                   LEFT JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                   LEFT JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE r.id=? LIMIT 1"
            );
            $statement->execute([(int) $sessionActor['id']]);
            $row = $statement->fetch();
            if (!$row || !(bool) $row['active'] || (int) $row['auth_version'] !== (int) $sessionActor['auth_version'] || !$row['occupancy_id']) {
                $this->app->session()->revokeLocal();
                return null;
            }
            return [
                'type' => 'resident', 'id' => (int) $row['id'], 'full_name' => $row['full_name'],
                'name' => $row['full_name'], 'phone' => $row['phone_norm'], 'email' => $row['email'],
                'line_user_id' => $row['line_user_id'], 'auth_version' => (int) $row['auth_version'],
                'occupancy_id' => (int) $row['occupancy_id'], 'room_id' => (int) $row['room_id'],
                'room_code' => $row['room_code'], 'role' => 'resident',
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
        $accountAllowed=$this->accountAttemptAllowed('admin-login-account',$accountIdentity,5,900,1800);
        static $dummyHash = null;
        $dummyHash ??= Password::hash('dummy-password-that-is-never-valid');
        $valid = Password::verify($password, $row['password_hash'] ?? $dummyHash);
        usleep(random_int(180000, 320000));
        if (!$accountAllowed || !$row || !(bool) $row['active'] || !$valid) {
            $this->app->audit()->write($request, null, 'auth.admin_failed', 'admin_user', null, [
                'principal_hash' => hash_hmac('sha256', $username, $this->app->config->appKey()),
                'account_rate_limited'=>!$accountAllowed,
            ]);
            throw new HttpException(401, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'INVALID_CREDENTIALS');
        }

        if (Password::needsRehash((string) $row['password_hash'])) {
            $rehash = $this->app->database()->pdo()->prepare('UPDATE admin_users SET password_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
            $rehash->execute([Password::hash($password), $row['id']]);
        }
        $actor = [
            'type' => 'admin', 'id' => (int) $row['id'], 'username' => $row['username'],
            'name' => $row['username'], 'role' => $row['role'], 'auth_version' => (int) $row['auth_version'],
        ];
        $this->app->session()->login($actor);
        $this->app->clearActorCache();
        $this->app->limiter()->clear('admin-login-account', $accountIdentity);
        $this->app->audit()->write($request, $actor, 'auth.admin_login', 'admin_user', $row['id']);
        return $actor;
    }

    /** @return array<string,mixed> */
    public function residentLogin(Request $request, array $input): array
    {
        Validator::only($input, ['phone', 'pin']);
        $rawPhone = is_scalar($input['phone'] ?? null) ? (string) $input['phone'] : '';
        try { $phone = Validator::phone($rawPhone); $phoneValid = true; }
        catch (HttpException) { $phone = ''; $phoneValid = false; }
        $pin = is_string($input['pin'] ?? null) ? $input['pin'] : '';
        $wellFormed = $phoneValid && (bool) preg_match('/^\d{6,12}$/', $pin);
        $ip = $this->app->security()->clientIp($request);
        $this->app->limiter()->hit('resident-login-ip', $ip, 30, 900, 900);

        $row = false;
        if ($wellFormed) {
            $statement = $this->app->database()->pdo()->prepare(
                "SELECT r.id,r.full_name,r.phone_norm,r.email,r.pin_hash,r.auth_version,r.active,
                        o.id AS occupancy_id,o.room_id,rm.room_code
                   FROM residents r
                   LEFT JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                   LEFT JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE r.phone_norm=? LIMIT 1"
            );
            $statement->execute([$phone]);
            $row = $statement->fetch();
        }
        $accountIdentity=$row?(string)$row['phone_norm']:'unknown:'.$ip;
        $accountAllowed=$this->accountAttemptAllowed('resident-login-account',$accountIdentity,8,900,1800);
        static $dummyHash = null;
        $dummyHash ??= Password::hash('9876543210');
        $valid = Password::verify($pin, $row['pin_hash'] ?? $dummyHash);
        usleep(random_int(180000, 320000));
        if (!$accountAllowed || !$row || !(bool) $row['active'] || !$row['occupancy_id'] || !$valid) {
            $this->app->audit()->write($request, null, 'auth.resident_failed', 'resident', null, [
                'principal_hash' => hash_hmac('sha256', $phoneValid?$phone:trim($rawPhone), $this->app->config->appKey()),
                'account_rate_limited'=>!$accountAllowed,
            ]);
            throw new HttpException(401, 'เบอร์โทรหรือ PIN ไม่ถูกต้อง', 'INVALID_CREDENTIALS');
        }
        if (Password::needsRehash((string) $row['pin_hash'])) {
            $rehash = $this->app->database()->pdo()->prepare('UPDATE residents SET pin_hash=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
            $rehash->execute([Password::hash($pin), $row['id']]);
        }
        $actor = [
            'type' => 'resident', 'id' => (int) $row['id'], 'full_name' => $row['full_name'],
            'name' => $row['full_name'], 'phone' => $row['phone_norm'], 'email' => $row['email'],
            'auth_version' => (int) $row['auth_version'], 'occupancy_id' => (int) $row['occupancy_id'],
            'room_id' => (int) $row['room_id'], 'room_code' => $row['room_code'], 'role' => 'resident',
        ];
        $this->app->session()->login($actor);
        $this->app->clearActorCache();
        $this->app->limiter()->clear('resident-login-account', $accountIdentity);
        $this->app->audit()->write($request, $actor, 'auth.resident_login', 'resident', $row['id']);
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
}
