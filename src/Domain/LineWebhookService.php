<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use JsonException;

final class LineWebhookService
{
    private const REPLY_ENDPOINT = 'https://api.line.me/v2/bot/message/reply';
    private const MAX_RAW_BYTES = 262_144;
    private const MAX_EVENTS = 50;
    private const MAX_REPLIES = 20;

    public function __construct(private readonly Application $app) {}

    /** @return array{events:int,replied:int,duplicates:int,skipped:int} */
    public function handle(Request $request): array
    {
        $secret = trim((string) $this->app->settings()->value('LINE_CHANNEL_SECRET', ''));
        $token = trim((string) $this->app->settings()->value('LINE_CHANNEL_ACCESS_TOKEN', ''));
        if ($secret === '' || $token === '') {
            throw new HttpException(503, 'LINE webhook is not configured', 'LINE_WEBHOOK_NOT_CONFIGURED');
        }

        $raw = $request->rawBody;
        if (strlen($raw) > self::MAX_RAW_BYTES) {
            throw new HttpException(413, 'LINE webhook body is too large', 'LINE_WEBHOOK_BODY_INVALID');
        }
        $this->assertSignature($request, $raw, $secret);
        if ($raw === '') {
            throw new HttpException(400, 'LINE webhook body is empty', 'LINE_WEBHOOK_BODY_INVALID');
        }
        if (!str_contains(strtolower((string) $request->header('content-type')), 'application/json')) {
            throw new HttpException(415, 'LINE webhook must be JSON', 'LINE_WEBHOOK_CONTENT_TYPE_INVALID');
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'Malformed LINE webhook JSON', 'LINE_WEBHOOK_JSON_INVALID');
        }
        $events = is_array($payload) ? ($payload['events'] ?? null) : null;
        if (!is_array($events) || !array_is_list($events) || count($events) > self::MAX_EVENTS) {
            throw new HttpException(422, 'LINE webhook events are invalid', 'LINE_WEBHOOK_EVENTS_INVALID');
        }

        $result = ['events' => count($events), 'replied' => 0, 'duplicates' => 0, 'skipped' => 0];
        $seen = [];
        $replyAttempts = 0;
        foreach ($events as $event) {
            $candidate = $this->replyCandidate($event);
            if ($candidate === null) {
                $result['skipped']++;
                continue;
            }
            if (isset($seen[$candidate['event_id']])) {
                $result['duplicates']++;
                continue;
            }
            $seen[$candidate['event_id']] = true;
            if ($replyAttempts >= self::MAX_REPLIES) {
                $result['skipped']++;
                continue;
            }
            $replyAttempts++;

            $disposition = $this->withEventLock($candidate['event_id'], function () use ($request, $token, $candidate): string {
                if ($this->eventAlreadyHandled($candidate['event_id'])) {
                    return 'duplicate';
                }
                $replyDisposition = $this->replyWithUserId($token, $candidate['reply_token'], $candidate['line_user_id']);
                $this->app->audit()->writeStrict(
                    $request,
                    null,
                    $replyDisposition === 'replied'
                        ? 'line.webhook_user_id_replied'
                        : 'line.webhook_reply_token_unavailable',
                    'line_webhook',
                    $candidate['event_id'],
                    [
                        'event_id' => $candidate['event_id'],
                        'event_type' => $candidate['event_type'],
                        'line_user_id_hash' => $this->identityHash($candidate['line_user_id']),
                    ],
                );
                return $replyDisposition;
            });
            if ($disposition === 'replied') {
                $result['replied']++;
            } elseif ($disposition === 'duplicate') {
                $result['duplicates']++;
            } else {
                $result['skipped']++;
            }
        }
        return $result;
    }

    private function assertSignature(Request $request, string $raw, string $secret): void
    {
        $provided = trim((string) $request->header('x-line-signature'));
        $decoded = strlen($provided) === 44 ? base64_decode($provided, true) : false;
        $expected = hash_hmac('sha256', $raw, $secret, true);
        if (!is_string($decoded) || strlen($decoded) !== 32
            || !hash_equals(base64_encode($decoded), $provided)
            || !hash_equals($expected, $decoded)) {
            throw new HttpException(401, 'Invalid LINE webhook signature', 'LINE_WEBHOOK_SIGNATURE_INVALID');
        }
    }

    /** @return array{event_id:string,event_type:string,line_user_id:string,reply_token:string}|null */
    private function replyCandidate(mixed $event): ?array
    {
        if (!is_array($event) || ($event['mode'] ?? null) !== 'active') {
            return null;
        }
        $eventId = $event['webhookEventId'] ?? null;
        $type = $event['type'] ?? null;
        $source = $event['source'] ?? null;
        $replyToken = $event['replyToken'] ?? null;
        if (!is_string($eventId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $eventId) !== 1
            || !is_string($type) || !in_array($type, ['follow', 'message'], true)
            || !is_array($source) || ($source['type'] ?? null) !== 'user'
            || !is_string($replyToken) || preg_match('/^[0-9A-Za-z_-]{16,128}$/D', $replyToken) !== 1) {
            return null;
        }
        if ($type === 'message') {
            $message = $event['message'] ?? null;
            if (!is_array($message) || ($message['type'] ?? null) !== 'text') {
                return null;
            }
        }
        $lineUserId = $source['userId'] ?? null;
        if (!is_string($lineUserId) || preg_match('/^U[0-9a-f]{32}$/D', $lineUserId) !== 1) {
            return null;
        }
        return [
            'event_id' => $eventId,
            'event_type' => $type,
            'line_user_id' => $lineUserId,
            'reply_token' => $replyToken,
        ];
    }

    private function eventAlreadyHandled(string $eventId): bool
    {
        $statement = $this->app->database()->pdo()->prepare(
            "SELECT 1 FROM audit_logs WHERE action IN ('line.webhook_user_id_replied','line.webhook_reply_token_unavailable') AND entity_type='line_webhook' AND entity_id=? LIMIT 1",
        );
        $statement->execute([$eventId]);
        return $statement->fetchColumn() !== false;
    }

    private function withEventLock(string $eventId, callable $callback): mixed
    {
        $pdo = $this->app->database()->pdo();
        $name = 'dormitory:line-webhook:' . substr(hash_hmac('sha256', $eventId, $this->app->config->appKey()), 0, 24);
        $acquire = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $acquire->execute([$name]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new HttpException(503, 'LINE webhook is busy', 'LINE_WEBHOOK_BUSY');
        }
        try {
            return $callback();
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$name]);
            } catch (\Throwable $error) {
                error_log('[line-webhook-lock] ' . $error->getMessage());
            }
        }
    }

    private function identityHash(string $lineUserId): string
    {
        return hash_hmac('sha256', "line-webhook-user\0{$lineUserId}", $this->app->config->appKey());
    }

    /** @return 'replied'|'token_unavailable' */
    private function replyWithUserId(string $token, string $replyToken, string $lineUserId): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }
        $body = json_encode([
            'replyToken' => $replyToken,
            'messages' => [[
                'type' => 'text',
                'text' => "LINE User ID ของคุณคือ\n{$lineUserId}\n\nคัดลอกรหัสนี้ไปวางที่หน้าโปรไฟล์ผู้พัก ในส่วน “รับบิลผ่าน LINE” แล้วกดส่งรหัสยืนยัน",
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init(self::REPLY_ENDPOINT);
        if ($ch === false) {
            throw new \RuntimeException('Cannot initialize LINE reply request');
        }
        $response = '';
        $tooLarge = false;
        $configured = curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 65_536) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (!$configured) {
            curl_close($ch);
            throw new \RuntimeException('Cannot configure LINE reply request');
        }
        $executed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = (int) curl_errno($ch);
        curl_close($ch);
        if ($tooLarge) {
            throw new HttpException(502, 'LINE reply response is too large', 'LINE_WEBHOOK_REPLY_FAILED');
        }
        if ($executed === false) {
            throw new HttpException(502, 'LINE reply temporarily failed', 'LINE_WEBHOOK_REPLY_FAILED', ['curl_code' => $curlError]);
        }
        if ($status >= 200 && $status < 300) {
            return 'replied';
        }
        if ($status === 400) {
            // A redelivered webhook retains the original one-use reply token.
            // The locally generated payload is valid, so record this distinct
            // ambiguous terminal state without claiming that a reply was sent.
            return 'token_unavailable';
        }
        if ($status === 401 || $status === 403) {
            throw new HttpException(503, 'LINE reply credential was rejected', 'LINE_WEBHOOK_REPLY_NOT_CONFIGURED');
        }
        throw new HttpException(502, 'LINE reply temporarily failed', 'LINE_WEBHOOK_REPLY_FAILED', ['http_status' => $status]);
    }
}
