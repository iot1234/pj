<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Config;

final class SessionManager
{
    private const ACTOR_KEY = 'dormitory_actor';
    private const CSRF_KEY = 'dormitory_csrf';
    private const CREATED_KEY = 'dormitory_created_at';
    private const LAST_SEEN_KEY = 'dormitory_last_seen_at';
    private const LINE_LINK_KEY = 'dormitory_line_link_challenge';
    private string $name;
    private bool $initialized = false;

    public function __construct(private readonly Config $config)
    {
        $secure = $this->isHttps();
        $base=(string)$this->config->get('SESSION_NAME','dormitory_session');
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/',$base))throw new \RuntimeException('SESSION_NAME is invalid');
        $this->name=$secure?'__Host-'.$base:$base;
        if(session_status()!==PHP_SESSION_ACTIVE){
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public function hasPersistedSession(): bool
    {
        if(session_status()===PHP_SESSION_ACTIVE)return true;
        $id=$_COOKIE[$this->name]??null;
        if(!is_string($id)||!preg_match('/^[A-Za-z0-9,-]{22,128}$/D',$id))return false;
        $directory=(string)ini_get('session.save_path');
        if($directory===''||str_contains($directory,';'))return false;
        return is_file(rtrim($directory,'/\\').DIRECTORY_SEPARATOR.'sess_'.$id);
    }

    private function start(bool $allowCreate): bool
    {
        if(session_status()!==PHP_SESSION_ACTIVE){
            if(!$allowCreate&&!$this->hasPersistedSession())return false;
            if($allowCreate&&!$this->hasPersistedSession())unset($_COOKIE[$this->name]);
            if(!session_start())throw new \RuntimeException('Unable to start the session');
        }
        if($this->initialized)return true;
        $now = time();
        $lifetime = $this->config->intInRange('SESSION_LIFETIME_SECONDS', 43200, 300, 604800);
        $createdAt = (int) ($_SESSION[self::CREATED_KEY] ?? 0);
        $lastSeenAt = (int) ($_SESSION[self::LAST_SEEN_KEY] ?? 0);
        if (($createdAt > 0 && $now - $createdAt > $lifetime)
            || ($lastSeenAt > 0 && $now - $lastSeenAt > $lifetime)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION[self::CREATED_KEY] ??= $now;
        $_SESSION[self::LAST_SEEN_KEY] = $now;
        if (!isset($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        $this->initialized=true;
        return true;
    }

    /** @return array<string,mixed>|null */
    public function actor(): ?array
    {
        if(!$this->start(false))return null;
        $actor = $_SESSION[self::ACTOR_KEY] ?? null;
        return is_array($actor) ? $actor : null;
    }

    /** @param array<string,mixed> $actor */
    public function login(array $actor): void
    {
        $this->start(true);
        session_regenerate_id(true);
        unset($_SESSION[self::LINE_LINK_KEY]);
        $_SESSION[self::ACTOR_KEY] = $actor;
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        $_SESSION[self::CREATED_KEY] = time();
        $_SESSION[self::LAST_SEEN_KEY] = time();
    }

    public function logout(): void
    {
        if(!$this->start(false))return;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        $this->initialized=false;
    }

    /** @return array<string,mixed>|null */
    public function lineLinkChallenge(): ?array
    {
        if(!$this->start(false))return null;
        $challenge=$_SESSION[self::LINE_LINK_KEY]??null;
        return is_array($challenge)?$challenge:null;
    }

    /** @param array<string,mixed> $challenge */
    public function storeLineLinkChallenge(array $challenge): void
    {
        $this->start(true);
        $_SESSION[self::LINE_LINK_KEY]=$challenge;
    }

    public function clearLineLinkChallenge(): void
    {
        if(!$this->start(false))return;
        unset($_SESSION[self::LINE_LINK_KEY]);
    }

    public function revokeLocal(): void
    {
        if(!$this->start(false))return;
        unset($_SESSION[self::ACTOR_KEY],$_SESSION[self::LINE_LINK_KEY]);
        session_regenerate_id(true);
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        $_SESSION[self::CREATED_KEY] = time();
        $_SESSION[self::LAST_SEEN_KEY] = time();
    }

    public function csrfToken(): string
    {
        $this->start(true);
        return (string) $_SESSION[self::CSRF_KEY];
    }

    private function isHttps(): bool
    {
        return $this->config->requestIsHttps();
    }
}
