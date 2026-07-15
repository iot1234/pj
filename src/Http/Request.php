<?php
declare(strict_types=1);

namespace Dormitory\Http;

use JsonException;

final class Request
{
    private const MAX_BODY_BYTES = 5_500_000;

    /** @param array<string,string> $headers
     *  @param array<string,mixed> $query
     *  @param array<string,mixed> $body
     *  @param array<string,mixed> $files
     *  @param array<string,string> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $server,
        public readonly string $requestId,
        private array $params = [],
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        if (str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', str_replace('\\', '/', $path))) {
            throw new HttpException(400, 'Invalid request path', 'BAD_PATH');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $contentLength = (int) ($headers['content-length'] ?? 0);
        if ($contentLength > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Request body is too large', 'REQUEST_TOO_LARGE');
        }

        $body = $_POST;
        $contentType = strtolower((string) ($headers['content-type'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            // Do not rely only on Content-Length: a chunked or dishonest
            // client can omit it. Read at most one byte beyond the limit so
            // oversized JSON never needs to be buffered in full.
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
            if (is_string($raw) && strlen($raw) > self::MAX_BODY_BYTES) {
                throw new HttpException(413, 'Request body is too large', 'REQUEST_TOO_LARGE');
            }
            if ($raw === false || $raw === '') {
                $body = [];
            } else {
                try {
                    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new HttpException(400, 'Malformed JSON body', 'INVALID_JSON');
                }
                if (!is_array($decoded)) {
                    throw new HttpException(400, 'JSON body must be an object', 'INVALID_JSON_SHAPE');
                }
                $body = $decoded;
            }
        }

        return new self(
            $method,
            $path,
            $headers,
            $_GET,
            is_array($body) ? $body : [],
            $_FILES,
            array_filter($_SERVER, 'is_string'),
            bin2hex(random_bytes(8)),
        );
    }

    /** @param array<string,string> $params */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function param(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isMutation(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
