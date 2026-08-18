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
    private const MAX_REPLIES = 2;
    private const WEBHOOK_DEADLINE_MILLISECONDS = 8_000;
    private const MIN_REPLY_START_MILLISECONDS = 1_250;
    private const REPLY_TIMEOUT_MILLISECONDS = 3_000;
    private const REPLY_CONNECT_TIMEOUT_MILLISECONDS = 1_000;
    private const REPLY_DEADLINE_GUARD_MILLISECONDS = 250;

    private const INSTRUCTION_USER_MAX = 1;
    private const INSTRUCTION_USER_WINDOW_SECONDS = 300;
    private const INSTRUCTION_GLOBAL_MAX = 120;
    private const INSTRUCTION_GLOBAL_WINDOW_SECONDS = 60;
    private const BIND_USER_MAX = 5;
    private const BIND_USER_WINDOW_SECONDS = 60;
    private const BIND_GLOBAL_MAX = 60;
    private const BIND_GLOBAL_WINDOW_SECONDS = 60;

    public function __construct(private readonly Application $app) {}

    /** @return array{events:int,replied:int,duplicates:int,skipped:int} */
    public function handle(Request $request): array
    {
        $deadlineNanoseconds = self::monotonicNanoseconds()
            + (self::WEBHOOK_DEADLINE_MILLISECONDS * 1_000_000);
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
        $deferred = 0;
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
            if (self::remainingMilliseconds($deadlineNanoseconds) <= 0) {
                $deferred++;
                continue;
            }
            $disposition = $this->withEventLock($candidate['event_id'], $deadlineNanoseconds, function () use ($request, $token, $candidate, $deadlineNanoseconds, &$replyAttempts): string {
                if ($this->eventAlreadyHandled($candidate['event_id'])) {
                    return 'duplicate';
                }
                // Never acknowledge an unhandled valid event when this request
                // has no safe time or outbound capacity left. The final 503
                // lets LINE redeliver it; completed audit rows remain deduped.
                if (!self::canStartReply($deadlineNanoseconds, $replyAttempts)) {
                    return 'deferred';
                }

                $category = self::replyCategory($candidate);
                $allowance = $this->consumeReplyAllowance($candidate, $category);
                if ($allowance !== 'allowed') {
                    // A correctly formed BIND code may still be valid. Leave
                    // that event unhandled so provider redelivery can retry it
                    // after the short, separate BIND allowance resets.
                    if ($category === 'bind') {
                        return 'deferred';
                    }
                    // A throttled event is intentionally consumed and audited
                    // so LINE redelivery cannot turn it into a reply storm.
                    $this->writeEventAudit(
                        $request,
                        $candidate,
                        'line.webhook_reply_suppressed',
                        $category . '_rate_limited',
                        ['rate_limit_scope' => $allowance],
                    );
                    return 'suppressed';
                }

                $reply = $this->replyTextForCandidate($request, $candidate);
                if (!self::canStartReply($deadlineNanoseconds, $replyAttempts)) {
                    return 'deferred';
                }
                $replyDisposition = $this->replyWithText(
                    $token,
                    $candidate['reply_token'],
                    $reply['text'],
                    $deadlineNanoseconds,
                );
                if ($replyDisposition === 'deferred') {
                    return 'deferred';
                }
                $replyAttempts++;
                $this->writeEventAudit(
                    $request,
                    $candidate,
                    $replyDisposition === 'replied'
                        ? 'line.webhook_reply_sent'
                        : 'line.webhook_reply_token_unavailable',
                    $reply['outcome'],
                );
                return $replyDisposition;
            });
            if ($disposition === 'replied') {
                $result['replied']++;
            } elseif ($disposition === 'duplicate') {
                $result['duplicates']++;
            } elseif ($disposition === 'deferred') {
                $deferred++;
            } else {
                $result['skipped']++;
            }
        }
        if ($deferred > 0) {
            throw new HttpException(
                503,
                'LINE webhook batch is only partially processed; redelivery is required',
                'LINE_WEBHOOK_BATCH_DEFERRED',
                ['deferred_events' => $deferred],
            );
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

    /** @return array{event_id:string,event_type:string,line_user_id:string,reply_token:string,message_text:?string}|null */
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
        $messageText = null;
        if ($type === 'message') {
            $message = $event['message'] ?? null;
            if (!is_array($message) || ($message['type'] ?? null) !== 'text'
                || !is_string($message['text'] ?? null) || strlen($message['text']) > 512) {
                return null;
            }
            $messageText = $message['text'];
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
            'message_text' => $messageText,
        ];
    }

    private function eventAlreadyHandled(string $eventId): bool
    {
        $statement = $this->app->database()->pdo()->prepare(
            "SELECT 1 FROM audit_logs WHERE action IN ('line.webhook_user_id_replied','line.webhook_reply_sent','line.webhook_reply_token_unavailable','line.webhook_reply_suppressed') AND entity_type='line_webhook' AND entity_id=? LIMIT 1",
        );
        $statement->execute([$eventId]);
        return $statement->fetchColumn() !== false;
    }

    private function withEventLock(string $eventId, int $deadlineNanoseconds, callable $callback): mixed
    {
        $remainingMilliseconds = self::remainingMilliseconds($deadlineNanoseconds);
        if ($remainingMilliseconds <= 0) {
            return 'deferred';
        }
        $pdo = $this->app->database()->pdo();
        $name = 'dormitory:line-webhook:' . substr(hash_hmac('sha256', $eventId, $this->app->config->appKey()), 0, 24);
        $lockWaitSeconds = $remainingMilliseconds >= 2_000 ? 1 : 0;
        $acquire = $pdo->prepare('SELECT GET_LOCK(?,?)');
        $acquire->execute([$name, $lockWaitSeconds]);
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

    /**
     * @param array{event_id:string,event_type:string,line_user_id:string,reply_token:string,message_text:?string} $candidate
     * @return 'instructions'|'bind'
     */
    private static function replyCategory(array $candidate): string
    {
        $message = trim((string) ($candidate['message_text'] ?? ''));
        return $candidate['event_type'] === 'message'
            && preg_match('/^BIND-[A-F0-9]{32}$/D', $message) === 1
                ? 'bind'
                : 'instructions';
    }

    /**
     * Consume the per-user and fleet allowance atomically. The identity passed
     * to RateLimiter is already an application-keyed HMAC, never a raw LINE ID.
     *
     * @param array{event_id:string,event_type:string,line_user_id:string,reply_token:string,message_text:?string} $candidate
     * @param 'instructions'|'bind' $category
     * @return 'allowed'|'user'|'global'
     */
    private function consumeReplyAllowance(array $candidate, string $category): string
    {
        $identity = $this->identityHash($candidate['line_user_id']);
        if ($category === 'bind') {
            $user = ['line-webhook-bind-user', self::BIND_USER_MAX, self::BIND_USER_WINDOW_SECONDS];
            $global = ['line-webhook-bind-global', self::BIND_GLOBAL_MAX, self::BIND_GLOBAL_WINDOW_SECONDS];
        } else {
            $user = ['line-webhook-instruction-user', self::INSTRUCTION_USER_MAX, self::INSTRUCTION_USER_WINDOW_SECONDS];
            $global = ['line-webhook-instruction-global', self::INSTRUCTION_GLOBAL_MAX, self::INSTRUCTION_GLOBAL_WINDOW_SECONDS];
        }

        $stage = 'user';
        try {
            $this->app->database()->transaction(function () use ($identity, $user, $global, &$stage): void {
                // Database::transaction may retry this closure after a
                // deadlock, so restore the diagnostic stage on every attempt.
                $stage = 'user';
                $this->app->limiter()->hit($user[0], $identity, $user[1], $user[2], $user[2]);
                $stage = 'global';
                $this->app->limiter()->hit($global[0], 'all-line-webhook-users', $global[1], $global[2], $global[2]);
            });
            return 'allowed';
        } catch (HttpException $error) {
            if ($error->status !== 429 || $error->errorCode !== 'RATE_LIMITED') {
                throw $error;
            }
            return $stage;
        }
    }

    /**
     * @param array{event_id:string,event_type:string,line_user_id:string,reply_token:string,message_text:?string} $candidate
     * @param array<string,mixed> $extra
     */
    private function writeEventAudit(
        Request $request,
        array $candidate,
        string $action,
        string $outcome,
        array $extra = [],
    ): void {
        $this->app->audit()->writeStrict(
            $request,
            null,
            $action,
            'line_webhook',
            $candidate['event_id'],
            $extra + [
                'event_id' => $candidate['event_id'],
                'event_type' => $candidate['event_type'],
                'reply_outcome' => $outcome,
                'line_user_id_hash' => $this->identityHash($candidate['line_user_id']),
            ],
        );
    }

    private static function monotonicNanoseconds(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new \RuntimeException('A monotonic 64-bit clock is required for LINE webhooks');
        }
        return $now;
    }

    private static function remainingMilliseconds(int $deadlineNanoseconds, ?int $nowNanoseconds = null): int
    {
        $nowNanoseconds ??= self::monotonicNanoseconds();
        $remaining = $deadlineNanoseconds - $nowNanoseconds;
        return $remaining <= 0 ? 0 : intdiv($remaining, 1_000_000);
    }

    private static function canStartReply(
        int $deadlineNanoseconds,
        int $replyAttempts,
        ?int $nowNanoseconds = null,
    ): bool {
        return $replyAttempts < self::MAX_REPLIES
            && self::remainingMilliseconds($deadlineNanoseconds, $nowNanoseconds) >= self::MIN_REPLY_START_MILLISECONDS;
    }

    /**
     * @param array{event_id:string,event_type:string,line_user_id:string,reply_token:string,message_text:?string} $candidate
     * @return array{text:string,outcome:string}
     */
    private function replyTextForCandidate(Request $request, array $candidate): array
    {
        $message = trim((string) ($candidate['message_text'] ?? ''));
        if ($candidate['event_type'] === 'follow' || $message === '') {
            return [
                'text' => "ต้องการรับบิลผ่าน LINE ให้เข้าสู่ระบบผู้พัก เปิดหน้าโปรไฟล์ กด “สร้างรหัสผูก LINE” แล้วส่งรหัส BIND-… กลับมาในแชตส่วนตัวนี้",
                'outcome' => 'instructions',
            ];
        }
        if (!str_starts_with(strtoupper($message), 'BIND-')) {
            return [
                'text' => "ยังไม่พบรหัสผูกบัญชี กรุณาสร้างรหัสจากหน้าโปรไฟล์ผู้พัก แล้วส่งข้อความที่ขึ้นต้นด้วย BIND- กลับมา รหัสใช้ได้ 10 นาที",
                'outcome' => 'instructions',
            ];
        }
        if (preg_match('/^BIND-[A-F0-9]{32}$/D', $message) !== 1) {
            return [
                'text' => 'รหัสผูก LINE มีรูปแบบไม่ถูกต้อง กรุณาสร้างรหัสใหม่จากหน้าโปรไฟล์แล้วส่งข้อความตามที่แสดงทุกตัว',
                'outcome' => 'invalid_code',
            ];
        }

        try {
            $binding = $this->app->lineBindings()->consumeSerialized(
                $message,
                $candidate['line_user_id'],
                function (array $bound) use ($request): void {
                    $residentId = (int) $bound['resident_id'];
                    $lineUserId = (string) $bound['line_user_id'];
                    if ($this->app->notifications()->isLineBindingVerified($residentId, $lineUserId)) {
                        return;
                    }
                    $this->app->database()->transaction(function () use ($request, $residentId, $lineUserId): void {
                        $this->app->audit()->writeStrict(
                            $request,
                            null,
                            'resident.line_link_verified',
                            'resident',
                            $residentId,
                            [
                                'method' => 'self_service_code',
                                'line_user_id_hint' => '•••' . substr($lineUserId, -6),
                                'line_user_id_hash' => $this->app->notifications()->lineBindingHash($residentId, $lineUserId),
                            ],
                        );
                    });
                },
            );
            return [
                'text' => ($binding['newly_bound'] ?? false)
                    ? 'ผูกบัญชี LINE สำเร็จแล้ว คุณจะได้รับบิลและการแจ้งเตือนของห้องนี้ผ่านบัญชีนี้'
                    : 'บัญชี LINE นี้ผูกกับผู้พักเรียบร้อยแล้ว ไม่ต้องดำเนินการซ้ำ',
                'outcome' => ($binding['newly_bound'] ?? false) ? 'bound' : 'already_bound',
            ];
        } catch (HttpException $error) {
            return match ($error->errorCode) {
                'LINE_ID_IN_USE' => [
                    'text' => 'บัญชี LINE นี้ผูกกับผู้พักรายอื่นแล้ว กรุณาติดต่อผู้ดูแลหอพัก',
                    'outcome' => 'line_in_use',
                ],
                'LINE_ALREADY_LINKED' => [
                    'text' => 'บัญชีผู้พักผูก LINE อยู่แล้ว หากต้องการเปลี่ยนบัญชีให้ยกเลิกการผูกจากหน้าโปรไฟล์ก่อน',
                    'outcome' => 'resident_already_linked',
                ],
                'RESIDENT_INACTIVE', 'RESIDENT_NOT_FOUND' => [
                    'text' => 'ไม่พบสถานะผู้พักที่ใช้งานได้ กรุณาติดต่อผู้ดูแลหอพัก',
                    'outcome' => 'resident_inactive',
                ],
                'LINE_LINK_CODE_INVALID', 'LINE_LINK_CODE_EXPIRED' => [
                    'text' => 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ กรุณากลับไปสร้างรหัสใหม่จากหน้าโปรไฟล์ผู้พัก',
                    'outcome' => 'invalid_or_expired',
                ],
                default => throw $error,
            };
        }
    }

    /** @return 'replied'|'token_unavailable'|'deferred' */
    private function replyWithText(string $token, string $replyToken, string $text, int $deadlineNanoseconds): string
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required');
        }
        if ($text === '' || strlen($text) > 15_000) {
            throw new \RuntimeException('LINE reply text is invalid');
        }
        $body = json_encode([
            'replyToken' => $replyToken,
            'messages' => [[
                'type' => 'text',
                'text' => $text,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $remainingMilliseconds = self::remainingMilliseconds($deadlineNanoseconds);
        if ($remainingMilliseconds < self::MIN_REPLY_START_MILLISECONDS) {
            return 'deferred';
        }
        $timeoutMilliseconds = min(
            self::REPLY_TIMEOUT_MILLISECONDS,
            $remainingMilliseconds - self::REPLY_DEADLINE_GUARD_MILLISECONDS,
        );
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
            CURLOPT_CONNECTTIMEOUT_MS => min(self::REPLY_CONNECT_TIMEOUT_MILLISECONDS, $timeoutMilliseconds),
            CURLOPT_TIMEOUT_MS => $timeoutMilliseconds,
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
