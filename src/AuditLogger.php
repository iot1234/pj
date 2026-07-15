<?php
declare(strict_types=1);

namespace Dormitory;

use Dormitory\Http\Request;
use Dormitory\Security\Security;

final class AuditLogger
{
    public function __construct(
        private readonly Database $database,
        private readonly Security $security,
    ) {
    }

    /** @param array<string,mixed> $details
     *  @param array<string,mixed>|null $actor
     */
    public function write(Request $request, ?array $actor, string $action, ?string $entityType = null, int|string|null $entityId = null, array $details = []): void
    {
        try {
            $this->persist($request, $actor, $action, $entityType, $entityId, $details);
        } catch (\Throwable $error) {
            error_log('[audit] ' . $error->getMessage());
        }
    }

    /**
     * Fail-closed audit for high-impact changes such as credential rotation.
     * Call this inside the same database transaction as the mutation.
     *
     * @param array<string,mixed> $details
     * @param array<string,mixed>|null $actor
     */
    public function writeStrict(Request $request, ?array $actor, string $action, ?string $entityType = null, int|string|null $entityId = null, array $details = []): void
    {
        $this->persist($request, $actor, $action, $entityType, $entityId, $details);
    }

    /** @param array<string,mixed> $details @param array<string,mixed>|null $actor */
    private function persist(Request $request, ?array $actor, string $action, ?string $entityType, int|string|null $entityId, array $details): void
    {
        $encoded = json_encode($this->redact($details), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 16000) {
            $encoded = json_encode(['truncated' => true, 'preview' => self::utf8Cut($encoded, 15000)], JSON_THROW_ON_ERROR);
        }
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip,user_agent,request_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
        );
        $statement->execute([
            $actor['type'] ?? 'guest',
            $actor['id'] ?? null,
            self::utf8Cut($action, 100),
            $entityType,
            $entityId === null ? null : (string) $entityId,
            $encoded,
            $this->security->clientIp($request),
            self::utf8Cut((string) ($request->header('user-agent') ?? ''), 500),
            $request->requestId,
        ]);
    }

    private function redact(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/password|pin|token|secret|authorization|slip/i', $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $childKey => $child) {
                $out[$childKey] = $this->redact($child, (string) $childKey);
            }
            return $out;
        }
        return is_string($value) ? self::utf8Cut($value, 2000) : $value;
    }

    private static function utf8Cut(string $value, int $maxBytes): string
    {
        // Tokenize valid Unicode scalar encodings without /u so malformed
        // attacker-controlled bytes are skipped instead of failing the audit.
        preg_match_all(
            '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/s',
            $value,
            $matches,
        );
        $chunks = [];
        $length = 0;
        foreach ($matches[0] as $character) {
            $bytes = strlen($character);
            if ($length + $bytes > $maxBytes) {
                break;
            }
            $chunks[] = $character;
            $length += $bytes;
        }
        return implode('', $chunks);
    }
}
