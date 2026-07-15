<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Config;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;

final class Security
{
    private const GUEST_CSRF_TTL_SECONDS = 7200;

    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $session,
    ) {
    }

    public function assertMutation(Request $request): void
    {
        $origin = $request->header('origin') ?? $request->header('referer');
        if ($origin === null || trim($origin) === '') {
            throw new HttpException(403, 'Missing Origin/Referer header', 'ORIGIN_REQUIRED');
        }
        $originParts = parse_url($origin);
        if (!is_array($originParts) || !isset($originParts['scheme'],$originParts['host']) || isset($originParts['user']) || isset($originParts['pass'])) {
            throw new HttpException(403, 'Invalid request origin', 'ORIGIN_INVALID');
        }
        $originScheme=strtolower((string)$originParts['scheme']);
        if(!in_array($originScheme,['http','https'],true))throw new HttpException(403,'Invalid request origin','ORIGIN_INVALID');
        $originPort=(int)($originParts['port']??($originScheme==='https'?443:80));
        $originValue=$originScheme.'://'.strtolower((string)$originParts['host']).':'.$originPort;
        $expected = $this->config->get('APP_URL');
        if ($expected) {
            $expectedParts=parse_url($expected);
            if(!is_array($expectedParts)||!isset($expectedParts['scheme'],$expectedParts['host']))throw new \RuntimeException('APP_URL must be an absolute http(s) URL');
            $expectedScheme=strtolower((string)$expectedParts['scheme']);
            if(!in_array($expectedScheme,['http','https'],true))throw new \RuntimeException('APP_URL must use http or https');
            $expectedPort=(int)($expectedParts['port']??($expectedScheme==='https'?443:80));
            $expectedValue=$expectedScheme.'://'.strtolower((string)$expectedParts['host']).':'.$expectedPort;
        } else {
            $hostHeader = strtolower((string) ($request->header('host') ?? ''));
            [$expectedHost, $expectedPort] = $this->splitHostPort($hostHeader);
            if($expectedHost===null)throw new HttpException(403,'Host header is required','HOST_REQUIRED');
            $expectedScheme=$this->requestScheme($request);
            $expectedPort??=$expectedScheme==='https'?443:80;
            $expectedValue=$expectedScheme.'://'.strtolower($expectedHost).':'.$expectedPort;
        }
        if (!hash_equals($expectedValue, $originValue)) {
            throw new HttpException(403, 'Cross-origin request blocked', 'ORIGIN_MISMATCH');
        }

        $provided = (string) ($request->header('x-csrf-token') ?? '');
        $valid=$this->session->hasPersistedSession()
            ?($provided!==''&&hash_equals($this->session->csrfToken(),$provided))
            :$this->validGuestCsrfToken($provided);
        if (!$valid) {
            throw new HttpException(403, 'Invalid CSRF token', 'CSRF_INVALID');
        }
    }

    public function csrfToken(): string
    {
        if($this->session->hasPersistedSession())return $this->session->csrfToken();
        $expires=time()+self::GUEST_CSRF_TTL_SECONDS;
        $nonce=bin2hex(random_bytes(16));
        $body=$expires.'.'.$nonce;
        return 'g.'.$body.'.'.hash_hmac('sha256','guest-csrf:'.$body,$this->config->appKey());
    }

    private function validGuestCsrfToken(string $token): bool
    {
        if(!preg_match('/^g\.([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$token,$parts))return false;
        $expires=(int)$parts[1];$now=time();
        if($expires<=$now||$expires>$now+self::GUEST_CSRF_TTL_SECONDS)return false;
        $body=$parts[1].'.'.$parts[2];
        return hash_equals(hash_hmac('sha256','guest-csrf:'.$body,$this->config->appKey()),$parts[3]);
    }

    public function clientIp(Request $request): string
    {
        $remote = (string) ($request->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $railwayProxy = $this->config->isRailwayProxyRequest($request->header('x-railway-request-id'));
        if ($railwayProxy) {
            $real = trim((string) ($request->header('x-real-ip') ?? ''));
            if (filter_var($real, FILTER_VALIDATE_IP)) {
                return $real;
            }
        }
        if ($railwayProxy || $this->config->isTrustedProxyAddress($remote)) {
            $forwarded = $request->header('x-forwarded-for');
            if ($forwarded) {
                // A well-behaved proxy appends its peer to X-Forwarded-For.
                // Walk from the trusted edge towards the client and stop at
                // the first untrusted address; left-most values may be
                // supplied by an attacker and must not be trusted directly.
                $chain = array_reverse(array_map('trim', explode(',', $forwarded)));
                foreach ($chain as $candidate) {
                    if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
                    }
                    if (!$this->config->isTrustedProxyAddress($candidate)) {
                        return $candidate;
                    }
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /** @return array<string,string> */
    public function headers(string $requestId): array
    {
        return [
            'X-Request-ID' => $requestId,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ] + ($this->config->isProduction() ? ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains'] : []);
    }

    /** @return array{0:?string,1:?int} */
    private function splitHostPort(string $host): array
    {
        if ($host === '') {
            return [null, null];
        }
        $parsed = parse_url('http://' . $host);
        return [isset($parsed['host']) ? (string) $parsed['host'] : null, isset($parsed['port']) ? (int) $parsed['port'] : null];
    }

    private function requestScheme(Request $request): string
    {
        $remote=(string)($request->server['REMOTE_ADDR']??'');
        if($this->config->requestComesFromTrustedProxy($remote,$request->header('x-railway-request-id'))){
            $parts=array_map('trim',explode(',',(string)($request->header('x-forwarded-proto')??'')));
            $forwarded=strtolower((string)end($parts));
            if(in_array($forwarded,['http','https'],true))return $forwarded;
        }
        $https=strtolower((string)($request->server['HTTPS']??''));
        return ($https!==''&&$https!=='off')||(string)($request->server['SERVER_PORT']??'')==='443'?'https':'http';
    }
}
