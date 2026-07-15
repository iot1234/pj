<?php
declare(strict_types=1);

namespace Dormitory\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $data = null, int $status = 200, ?string $message = null): self
    {
        $payload = ['ok' => $status >= 200 && $status < 400, 'data' => self::normalizeDateTimes($data)];
        if ($message !== null) {
            $payload['message'] = $message;
        }
        return new self(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    /** @param array<string,mixed> $details */
    public static function error(string $message, int $status, string $code, array $details = []): self
    {
        return self::json(['code' => $code] + $details, $status, $message);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8','Cache-Control'=>'no-store']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    /** @param array<string,string> $securityHeaders */
    public function send(array $securityHeaders = []): never
    {
        http_response_code($this->status);
        foreach ($securityHeaders + $this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
        exit;
    }

    private static function normalizeDateTimes(mixed $value, ?string $key = null): mixed
    {
        if(is_array($value)){
            foreach($value as $childKey=>$childValue){
                $value[$childKey]=self::normalizeDateTimes($childValue,is_string($childKey)?$childKey:null);
            }
            return $value;
        }
        if(is_string($value)&&$key!==null&&str_ends_with($key,'_at')
            &&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/',$value)){
            return str_replace(' ','T',$value).'Z';
        }
        return $value;
    }
}
