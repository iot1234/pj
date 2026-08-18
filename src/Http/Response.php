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

    public static function htmlError(int $status, ?string $requestId = null): self
    {
        $status = max(400, min(599, $status));
        [$title, $message] = match ($status) {
            400 => ['คำขอไม่ถูกต้อง', 'ระบบไม่สามารถอ่านคำขอนี้ได้ กรุณากลับไปเริ่มรายการใหม่'],
            401 => ['กรุณาเข้าสู่ระบบ', 'เซสชันหมดอายุหรือยังไม่ได้เข้าสู่ระบบ'],
            403 => ['ไม่มีสิทธิ์เข้าถึง', 'บัญชีนี้ไม่มีสิทธิ์เปิดหน้าที่ร้องขอ'],
            404 => ['ไม่พบหน้าที่ต้องการ', 'หน้าที่คุณเปิดอาจถูกย้าย ลบ หรือพิมพ์ที่อยู่ไม่ถูกต้อง'],
            413 => ['ข้อมูลมีขนาดใหญ่เกินไป', 'ไฟล์หรือข้อมูลที่ส่งมีขนาดเกินขอบเขตที่ระบบรองรับ'],
            500 => ['ระบบขัดข้องชั่วคราว', 'ระบบไม่สามารถดำเนินการได้ในขณะนี้ กรุณาลองใหม่ภายหลัง'],
            default => ['ไม่สามารถเปิดหน้านี้ได้', 'ระบบไม่สามารถดำเนินการตามคำขอได้ กรุณากลับไปเริ่มรายการใหม่'],
        };
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reference = $status >= 500 && is_string($requestId) && trim($requestId) !== ''
            ? '<p class="muted">รหัสอ้างอิง: <code>' . $escape(trim($requestId)) . '</code></p>'
            : '';
        $html = '<!doctype html><html lang="th"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            . '<meta name="referrer" content="same-origin"><title>' . $status . ' · ' . $escape($title) . '</title>'
            . '<link rel="stylesheet" href="/assets/css/app.css"></head><body data-page="error">'
            . '<a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหาหลัก</a>'
            . '<main id="main-content" class="public-main"><section class="content-section" aria-labelledby="error-title">'
            . '<div class="panel"><span class="eyebrow">ข้อผิดพลาด ' . $status . '</span>'
            . '<h1 id="error-title">' . $escape($title) . '</h1><p>' . $escape($message) . '</p>'
            . $reference
            . '<div class="form-actions"><a class="button button-primary" href="/">กลับหน้าแรก</a></div>'
            . '</div></section></main></body></html>';

        return self::html($html, $status);
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
