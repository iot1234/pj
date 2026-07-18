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

    public function intInRange(string $key, int $default, int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new RuntimeException('Invalid integer configuration range');
        }
        $value = $this->int($key, $default);
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException("Environment variable {$key} must be between {$minimum} and {$maximum}");
        }
        return $value;
    }

    public static function validatedDbHost(string $host): string
    {
        $host=trim($host);
        if (preg_match('/^[A-Za-z0-9._:-]{1,253}$/D', $host) !== 1) {
            throw new RuntimeException('Environment variable DB_HOST has an invalid hostname or address');
        }
        return $host;
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
        $railwayRequestId = (string) ($_SERVER['HTTP_X_RAILWAY_REQUEST_ID'] ?? '');
        if ($this->requestComesFromTrustedProxy($remote, $railwayRequestId)) {
            $parts = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
            $forwarded = strtolower((string) end($parts));
            if (in_array($forwarded, ['http', 'https'], true)) {
                return $forwarded === 'https';
            }
        }
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /**
     * Trust explicitly configured proxy addresses/CIDRs. On Railway, public
     * requests are also trusted only when both Railway runtime identity and
     * the edge-generated request ID are present. This avoids accepting a
     * spoofed forwarded scheme on ordinary hosts.
     */
    public function requestComesFromTrustedProxy(string $remote, ?string $railwayRequestId = null): bool
    {
        if ($this->isTrustedProxyAddress($remote)) {
            return true;
        }

        return $this->isRailwayProxyRequest($railwayRequestId, $remote);
    }

    public function isRailwayProxyRequest(?string $railwayRequestId, ?string $remote = null): bool
    {
        $peer = trim($remote ?? (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return trim((string) $railwayRequestId) !== ''
            && trim((string) $this->get('RAILWAY_PROJECT_ID', '')) !== ''
            && trim((string) $this->get('RAILWAY_ENVIRONMENT_ID', '')) !== ''
            && trim((string) $this->get('RAILWAY_SERVICE_ID', '')) !== ''
            // Railway's public edge forwards requests to deployments from
            // its internal 100.0.0.0/8 proxy range. Runtime variables and an
            // attacker-supplied header alone must never make a public peer a
            // trusted proxy.
            && $this->ipInCidr($peer, '100.0.0.0/8');
    }

    public function isTrustedProxyAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $trusted = array_filter(array_map('trim', explode(',', (string) $this->get('TRUSTED_PROXIES', ''))));
        foreach ($trusted as $entry) {
            if ($entry === $address || (str_contains($entry, '/') && $this->ipInCidr($address, $entry))) {
                return true;
            }
        }
        return false;
    }

    private function ipInCidr(string $address, string $cidr): bool
    {
        [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, '');
        if ($network === '' || !preg_match('/^\d{1,3}$/D', $prefix)) {
            return false;
        }

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $prefix;
        $maximumBits = strlen($addressBytes) * 8;
        if ($bits < 0 || $bits > $maximumBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
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
        return self::validatedAppKey($key, $this->isProduction());
    }

    public static function validatedAppKey(string $key, bool $production): string
    {
        if (strlen($key) < 32 || stripos($key, 'change') !== false) {
            throw new RuntimeException('APP_KEY must be a random value of at least 32 characters and must not be a placeholder');
        }
        if (strlen($key) > 1024) {
            throw new RuntimeException('APP_KEY exceeds the supported maximum length');
        }
        if (!$production) {
            return $key;
        }
        $bytes = null;
        if (preg_match('/^[a-f0-9]{64}$/iD', $key) === 1) {
            $bytes = hex2bin($key);
        } else {
            $decoded = base64_decode($key, true);
            if (is_string($decoded) && strlen($decoded) >= 32
                && hash_equals(rtrim(base64_encode($decoded), '='), rtrim($key, '='))) {
                $bytes = $decoded;
            }
            if (!is_string($bytes) && preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $key) === 1) {
                $normalized = strtr(rtrim($key, '='), '-_', '+/');
                $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
                $decoded = base64_decode($normalized, true);
                if (is_string($decoded) && strlen($decoded) >= 32
                    && hash_equals(rtrim(strtr(base64_encode($decoded), '+/', '-_'), '='), rtrim($key, '='))) {
                    $bytes = $decoded;
                }
            }
            // Preserve compatibility with existing high-entropy printable
            // secrets that predate the documented hex/base64 formats. The
            // same diversity and pattern checks below still apply.
            if (!is_string($bytes) && preg_match('/^[^\x00-\x1F\x7F]{32,}$/sD', $key) === 1) {
                $bytes = $key;
            }
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('Production APP_KEY must contain at least 32 bytes of printable random key material');
        }
        if (count(array_unique(str_split($bytes))) < 16) {
            throw new RuntimeException('Production APP_KEY has insufficient diversity; generate a new random key');
        }
        $encodedLower = strtolower($key);
        $decodedLower = strtolower($bytes);
        foreach (['abcdefghijklmnopqrstuvwxyz','zyxwvutsrqponmlkjihgfedcba','0123456789','9876543210','qwertyuiop','poiuytrewq','asdfghjkl','lkjhgfdsa','zxcvbnm','mnbvcxz'] as $predictable) {
            if (str_contains($encodedLower, $predictable) || str_contains($decodedLower, $predictable)) {
                throw new RuntimeException('Production APP_KEY contains a predictable sequence; generate a new random key');
            }
        }
        $length = strlen($bytes);
        for ($period = 1; $period <= intdiv($length, 2); $period++) {
            if (substr(str_repeat(substr($bytes, 0, $period), (int) ceil($length / $period)), 0, $length) === $bytes) {
                throw new RuntimeException('Production APP_KEY contains a repeated pattern; generate a new random key');
            }
        }
        $delta = (ord($bytes[1]) - ord($bytes[0])) & 0xff;
        $sequence = true;
        for ($index = 2; $index < $length; $index++) {
            if (((ord($bytes[$index]) - ord($bytes[$index - 1])) & 0xff) !== $delta) {
                $sequence = false;
                break;
            }
        }
        if ($sequence) {
            throw new RuntimeException('Production APP_KEY contains a predictable sequence; generate a new random key');
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
        ini_set('session.gc_maxlifetime', (string) $this->intInRange('SESSION_LIFETIME_SECONDS', 43200, 300, 604800));
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
