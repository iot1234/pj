<?php
declare(strict_types=1);

namespace Dormitory;

use RuntimeException;

final class Config
{
    /** @param array<string,string> $values */
    private function __construct(
        private readonly array $values,
        public readonly string $root,
    ) {
    }

    public static function fromEnvironment(string $root): self
    {
        $values = [];
        $envFile = $root . '/.env';
        if (is_file($envFile) && is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                if ($key !== '' && getenv($key) === false) {
                    $values[$key] = $value;
                }
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_scalar($value) && preg_match('/^[A-Z][A-Z0-9_]+$/', $key)) {
                $values[$key] = (string) $value;
            }
        }

        return new self($values, $root);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $runtime = getenv($key);
        if ($runtime !== false) {
            return $runtime;
        }
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = trim((string) $this->get($key, ''));
        if ($value === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new RuntimeException("Environment variable {$key} must be a boolean"),
        };
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^-?\d+$/D', trim($value))) {
            throw new RuntimeException("Environment variable {$key} must be an integer");
        }
        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new RuntimeException("Environment variable {$key} is outside the supported integer range");
        }
        return $parsed;
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function forceHttps(): bool
    {
        return $this->bool('FORCE_HTTPS', $this->isProduction());
    }

    /**
     * Resolve the client-facing scheme without trusting spoofable forwarded
     * headers. X-Forwarded-Proto is honored only when REMOTE_ADDR is an
     * explicitly configured reverse proxy.
     */
    public function requestIsHttps(): bool
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = array_filter(array_map('trim', explode(',', (string) $this->get('TRUSTED_PROXIES', ''))));
        if (in_array($remote, $trusted, true)) {
            $parts = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            $forwarded = strtolower((string) end($parts));
            if (in_array($forwarded, ['http', 'https'], true)) {
                return $forwarded === 'https';
            }
        }
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public function enforceHttps(): void
    {
        if (PHP_SAPI === 'cli' || !$this->forceHttps() || $this->requestIsHttps()) {
            return;
        }
        $base = rtrim($this->require('APP_URL'), '/');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/' || preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            $uri = '/';
        }
        header('Location: ' . $base . $uri, true, 308);
        header('Cache-Control: no-store', true);
        exit;
    }

    public function appKey(): string
    {
        $key = $this->require('APP_KEY');
        if (strlen($key) < 32 || stripos($key, 'change') !== false) {
            throw new RuntimeException('APP_KEY must be a random value of at least 32 characters and must not be a placeholder');
        }
        return $key;
    }

    /**
     * Validate APP_URL as the single public origin used by redirects and
     * cookies. Keeping this rule here lets preflight tooling call the exact
     * same validator as the web runtime instead of maintaining a looser copy.
     */
    public static function validatedAppUrlScheme(string $url): string
    {
        $urlParts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($urlParts)
            || !isset($urlParts['scheme'], $urlParts['host'])
            || !in_array(strtolower((string) $urlParts['scheme']), ['http', 'https'], true)
            || trim((string) $urlParts['host']) === ''
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])
            || (isset($urlParts['path']) && !in_array($urlParts['path'], ['', '/'], true))) {
            throw new RuntimeException('APP_URL must be an HTTP(S) origin without credentials, path, query, or fragment');
        }

        return strtolower((string) $urlParts['scheme']);
    }

    public function configurePhp(): void
    {
        // Start from a non-disclosing baseline before validating environment
        // values. Production misconfiguration must not expose a bootstrap
        // stack trace even when the host php.ini has display_errors enabled.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('expose_php', '0');

        $production = $this->isProduction();
        $debug = $this->bool('APP_DEBUG', false);
        $forceHttps = $this->forceHttps();
        $url = $this->require('APP_URL');
        $urlScheme = self::validatedAppUrlScheme($url);
        // APP_KEY encrypts operational credentials and signs security
        // identifiers in every environment. A mislabeled deployment must not
        // silently fall back to a weak key.
        $this->appKey();

        $timezone = trim((string) $this->get('APP_TIMEZONE', 'Asia/Bangkok'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new RuntimeException('APP_TIMEZONE must be a valid timezone identifier');
        }

        if ($forceHttps && $urlScheme !== 'https') {
            throw new RuntimeException('APP_URL must use HTTPS when FORCE_HTTPS is true');
        }

        if ($production) {
            if ($debug) {
                throw new RuntimeException('APP_DEBUG must be false in production');
            }
            if (!$forceHttps) {
                throw new RuntimeException('FORCE_HTTPS must be true in production');
            }
        }
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        // PHP 8.4 fixes session IDs to a secure 128-bit hexadecimal value and
        // deprecates changing these two directives. Keep the stronger legacy
        // setting only on supported runtimes to avoid production deprecations.
        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '64');
            ini_set('session.sid_bits_per_character', '6');
        }
        ini_set('session.gc_maxlifetime', (string) $this->int('SESSION_LIFETIME_SECONDS', 43200));
        $sessionPath=$this->root.'/storage/sessions';
        if(!is_dir($sessionPath)&&!mkdir($sessionPath,0700,true)&&!is_dir($sessionPath))throw new RuntimeException('Cannot create session storage directory');
        ini_set('session.save_path',$sessionPath);
    }

    private function environment(): string
    {
        $environment = strtolower(trim((string) $this->get('APP_ENV', 'production')));
        if (!in_array($environment, ['development', 'staging', 'production', 'testing'], true)) {
            throw new RuntimeException('APP_ENV must be development, staging, production, or testing');
        }
        return $environment;
    }
}
