<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Support\MySqlError;
use PDO;
use PDOException;

final class LineBindingService
{
    private const CODE_PREFIX = 'BIND-';
    private const CODE_BYTES = 16;
    private const TTL_SECONDS = 600;
    private const CODE_PATTERN = '/^BIND-[A-F0-9]{32}$/D';
    private const LINE_USER_ID_PATTERN = '/^U[0-9a-f]{32}$/D';

    public function __construct(private readonly Application $app) {}

    /** @return array<string,mixed> */
    public function issue(int $residentId, int $expectedAuthVersion): array
    {
        if ($residentId < 1 || $expectedAuthVersion < 1) {
            throw new HttpException(401, 'Resident session is invalid', 'UNAUTHORIZED');
        }
        if (trim((string) $this->app->settings()->value('LINE_CHANNEL_ACCESS_TOKEN', '')) === ''
            || trim((string) $this->app->settings()->value('LINE_CHANNEL_SECRET', '')) === ''
            || trim((string) $this->app->settings()->value('LINE_BASIC_ID', '')) === '') {
            throw new HttpException(503, 'ยังไม่ได้ตั้งค่า LINE Messaging, Webhook และ Basic ID ให้ครบ', 'LINE_NOT_CONFIGURED');
        }
        if($this->app->lineRoomBindings()->isBlocked($residentId))throw new HttpException(409,'ผู้ดูแลปิดการผูก LINE ของห้องนี้ไว้','LINE_BINDING_BLOCKED');
        $this->app->lineOfficialAccounts()->credentials(0);

        return $this->app->database()->transaction(function (PDO $pdo) use ($residentId, $expectedAuthVersion): array {
            $resident = $pdo->prepare(
                "SELECT r.id,r.line_user_id,r.auth_version
                   FROM residents r
                   JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                   JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE r.id=? AND r.active=1
                  ORDER BY o.id DESC LIMIT 2 FOR UPDATE",
            );
            $resident->execute([$residentId]);
            $rows = $resident->fetchAll();
            if (count($rows) !== 1) {
                throw new HttpException(404, 'ไม่พบผู้พักที่กำลังเข้าพัก', 'RESIDENT_NOT_FOUND');
            }
            if ((int) $rows[0]['auth_version'] !== $expectedAuthVersion) {
                throw new HttpException(409, 'เซสชันผู้พักหมดอายุหลังข้อมูลบัญชีเปลี่ยนแปลง กรุณาเข้าสู่ระบบใหม่', 'RESIDENT_SESSION_STALE');
            }
            if (is_string($rows[0]['line_user_id']) && $rows[0]['line_user_id'] !== '') {
                throw new HttpException(409, 'บัญชีนี้ผูก LINE อยู่แล้ว กรุณายกเลิกการผูกก่อนสร้างรหัสใหม่', 'LINE_ALREADY_LINKED');
            }

            $expire = $pdo->prepare(
                "UPDATE line_link_codes
                    SET status='expired',updated_at=UTC_TIMESTAMP(6)
                  WHERE resident_id=? AND status='pending' AND expires_at<=UTC_TIMESTAMP(6)",
            );
            $expire->execute([$residentId]);
            $revoke = $pdo->prepare(
                "UPDATE line_link_codes
                    SET status='revoked',revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                  WHERE resident_id=? AND status='pending'",
            );
            $revoke->execute([$residentId]);

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $code = self::CODE_PREFIX . strtoupper(bin2hex(random_bytes(self::CODE_BYTES)));
                $hash = $this->codeHash($code);
                try {
                    $insert = $pdo->prepare(
                        "INSERT INTO line_link_codes
                            (resident_id,code_hash,status,expires_at,created_at,updated_at)
                         VALUES (?,?,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL " . self::TTL_SECONDS . " SECOND),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                    );
                    $insert->execute([$residentId, $hash]);
                    $id = (int) $pdo->lastInsertId();
                    $expiry = $pdo->prepare('SELECT expires_at FROM line_link_codes WHERE id=?');
                    $expiry->execute([$id]);
                    return [
                        'code' => $code,
                        'expires_at' => (string) $expiry->fetchColumn(),
                        'expires_in' => self::TTL_SECONDS,
                        'single_use' => true,
                        'line_binding_ready' => true,
                    ] + self::officialAccountLinks($this->app->settings()->value('LINE_BASIC_ID'), $code);
                } catch (PDOException $error) {
                    $codeCollision = MySqlError::isDuplicateKey($error, 'uq_line_link_codes_code_hash');
                    if (!$codeCollision || $attempt === 3) {
                        throw $error;
                    }
                }
            }
            throw new \RuntimeException('LINE link code generation exhausted');
        });
    }

    /** Issue on behalf of an active resident using the current credential version. */
    public function issueForAdmin(int $residentId): array
    {
        return $this->app->database()->transaction(function (PDO $pdo) use ($residentId): array {
            $statement = $pdo->prepare("SELECT r.auth_version
                FROM residents r
                JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                WHERE r.id=? AND r.active=1
                ORDER BY o.id DESC LIMIT 2 FOR UPDATE");
            $statement->execute([$residentId]);
            $rows = $statement->fetchAll();
            if (count($rows) !== 1) {
                throw new HttpException(404, 'ไม่พบผู้พักที่กำลังเข้าพัก', 'RESIDENT_NOT_FOUND');
            }
            return $this->issue($residentId, (int) $rows[0]['auth_version']);
        });
    }

    /**
     * LINE opens the OA chat with a draft; the resident must still press Send.
     * Never accept a caller-supplied host or include the one-time URL in audit.
     * @return array{line_add_friend_url:?string,line_message_url:?string}
     */
    public static function officialAccountLinks(mixed $basicId, ?string $code = null): array
    {
        $links = ['line_add_friend_url' => null, 'line_message_url' => null];
        if (!is_string($basicId) || preg_match('/^@[A-Za-z0-9._-]{1,32}$/D', $basicId) !== 1) {
            return $links;
        }
        $encodedId = rawurlencode($basicId);
        $links['line_add_friend_url'] = 'https://line.me/R/ti/p/' . $encodedId;
        if ($code !== null && preg_match(self::CODE_PATTERN, $code) === 1) {
            $links['line_message_url'] = 'https://line.me/R/oaMessage/' . $encodedId . '/?' . rawurlencode($code);
        }
        return $links;
    }

    /**
     * Serialize the binding mutation and its required proof callback against
     * unlink, profile identity changes, and move-out for the same resident.
     *
     * @param callable(array{resident_id:int,line_user_id:string,newly_bound:bool}):void $afterConsume
     * @return array{resident_id:int,line_user_id:string,newly_bound:bool}
     */
    public function consumeSerialized(string $rawCode, string $lineUserId, callable $afterConsume, int $lockWaitSeconds = 12): array
    {
        $code = trim($rawCode);
        $lineUserId = trim($lineUserId);
        // Reuse consume() for the public, fail-closed validation contract.
        if (preg_match(self::CODE_PATTERN, $code) !== 1
            || preg_match(self::LINE_USER_ID_PATTERN, $lineUserId) !== 1) {
            return $this->consume($code, $lineUserId);
        }

        $lookup = $this->app->database()->pdo()->prepare(
            'SELECT resident_id FROM line_link_codes WHERE code_hash=? LIMIT 1',
        );
        $lookup->execute([$this->codeHash($code)]);
        $residentId = $lookup->fetchColumn();
        if ($residentId === false) {
            return $this->consume($code, $lineUserId);
        }

        return $this->app->notifications()->withLineBindingLock(
            (int) $residentId,
            function () use ($code, $lineUserId, $afterConsume): array {
                $outcome = $this->app->database()->transaction(function () use ($code, $lineUserId, $afterConsume): array {
                    try {
                        $binding = $this->consume($code, $lineUserId);
                    } catch (HttpException $error) {
                        // Expiry/revocation updates must commit before returning
                        // their safe error. Proof failures below must roll back
                        // the actual binding and leave the code usable.
                        return ['error' => $error];
                    }
                    $afterConsume($binding);
                    return ['binding' => $binding];
                });
                if (isset($outcome['error'])) throw $outcome['error'];
                return $outcome['binding'];
            },
            $lockWaitSeconds,
        );
    }

    /**
     * @return array{resident_id:int,line_user_id:string,newly_bound:bool}
     */
    public function consume(string $rawCode, string $lineUserId): array
    {
        $code = trim($rawCode);
        $lineUserId = trim($lineUserId);
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new HttpException(422, 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ', 'LINE_LINK_CODE_INVALID');
        }
        if (preg_match(self::LINE_USER_ID_PATTERN, $lineUserId) !== 1) {
            throw new HttpException(422, 'LINE User ID ไม่ถูกต้อง', 'LINE_USER_ID_INVALID');
        }
        $hash = $this->codeHash($code);

        $outcome = $this->app->database()->transaction(function (PDO $pdo) use ($hash, $lineUserId): array {
            $lookup = $pdo->prepare('SELECT resident_id FROM line_link_codes WHERE code_hash=? LIMIT 1');
            $lookup->execute([$hash]);
            $residentId = $lookup->fetchColumn();
            if ($residentId === false) {
                return ['error' => 'invalid'];
            }
            $residentId = (int) $residentId;
            if($this->app->lineRoomBindings()->isBlocked($residentId))return ['error'=>'invalid'];

            // Lock order is resident -> challenge in both issue() and consume().
            // The first non-locking lookup only discovers an immutable FK.
            $resident = $pdo->prepare('SELECT id,line_user_id,active FROM residents WHERE id=? FOR UPDATE');
            $resident->execute([$residentId]);
            $residentRow = $resident->fetch();
            $challenge = $pdo->prepare(
                'SELECT id,resident_id,status,line_user_id,expires_at FROM line_link_codes WHERE code_hash=? FOR UPDATE',
            );
            $challenge->execute([$hash]);
            $row = $challenge->fetch();
            if (!$residentRow || !$row || (int) $row['resident_id'] !== $residentId) {
                return ['error' => 'invalid'];
            }

            if ($row['status'] === 'bound') {
                $sameLine = is_string($row['line_user_id'])
                    && hash_equals($row['line_user_id'], $lineUserId)
                    && is_string($residentRow['line_user_id'])
                    && hash_equals($residentRow['line_user_id'], $lineUserId);
                return $sameLine
                    ? ['resident_id' => $residentId, 'line_user_id' => $lineUserId, 'newly_bound' => false]
                    : ['error' => 'invalid'];
            }
            if ($row['status'] !== 'pending') {
                return ['error' => 'invalid'];
            }

            $expired = $pdo->prepare('SELECT expires_at<=UTC_TIMESTAMP(6) FROM line_link_codes WHERE id=?');
            $expired->execute([(int) $row['id']]);
            if ((int) $expired->fetchColumn() === 1) {
                $markExpired = $pdo->prepare(
                    "UPDATE line_link_codes SET status='expired',updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'",
                );
                $markExpired->execute([(int) $row['id']]);
                return ['error' => 'expired'];
            }

            $occupancy = $pdo->prepare(
                "SELECT o.id FROM occupancies o
                   JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                  WHERE o.resident_id=? AND o.status='active'
                  ORDER BY o.id DESC LIMIT 2 FOR UPDATE",
            );
            $occupancy->execute([$residentId]);
            $occupancies = $occupancy->fetchAll();
            if ((int) $residentRow['active'] !== 1 || count($occupancies) !== 1) {
                $revoke = $pdo->prepare(
                    "UPDATE line_link_codes SET status='revoked',revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'",
                );
                $revoke->execute([(int) $row['id']]);
                return ['error' => 'resident_inactive'];
            }
            if (is_string($residentRow['line_user_id']) && $residentRow['line_user_id'] !== '') {
                return ['error' => 'already_linked'];
            }

            $owner = $pdo->prepare('SELECT id FROM residents WHERE line_user_id=? AND id<>? LIMIT 1 FOR UPDATE');
            $owner->execute([$lineUserId, $residentId]);
            if ($owner->fetchColumn() !== false) {
                return ['error' => 'line_in_use'];
            }
            $newOwner=$pdo->prepare("SELECT id FROM line_room_bindings WHERE oa_id=0 AND line_user_id=? AND status='bound' LIMIT 1 FOR UPDATE");$newOwner->execute([$lineUserId]);
            if($newOwner->fetchColumn()!==false)return ['error'=>'line_in_use'];

            try {
                $bindResident = $pdo->prepare(
                    'UPDATE residents SET line_user_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND active=1 AND line_user_id IS NULL',
                );
                $bindResident->execute([$lineUserId, $residentId]);
                if ($bindResident->rowCount() !== 1) {
                    return ['error' => 'already_linked'];
                }
                $bindCode = $pdo->prepare(
                    "UPDATE line_link_codes
                        SET status='bound',line_user_id=?,bound_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                      WHERE id=? AND status='pending' AND expires_at>UTC_TIMESTAMP(6)",
                );
                $bindCode->execute([$lineUserId, (int) $row['id']]);
                if ($bindCode->rowCount() !== 1) {
                    throw new \RuntimeException('LINE link code changed during binding');
                }
                $revokeOthers = $pdo->prepare(
                    "UPDATE line_link_codes
                        SET status='revoked',revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                      WHERE resident_id=? AND id<>? AND status='pending'",
                );
                $revokeOthers->execute([$residentId, (int) $row['id']]);
            } catch (PDOException $error) {
                if (MySqlError::isDuplicateKey($error, 'uq_residents_line_user_id')) {
                    return ['error' => 'line_in_use'];
                }
                throw $error;
            }

            return ['resident_id' => $residentId, 'line_user_id' => $lineUserId, 'newly_bound' => true];
        });

        return match ($outcome['error'] ?? null) {
            null => $outcome,
            'expired' => throw new HttpException(410, 'รหัสผูก LINE หมดอายุ กรุณาสร้างรหัสใหม่', 'LINE_LINK_CODE_EXPIRED'),
            'resident_inactive' => throw new HttpException(409, 'ผู้พักไม่ได้อยู่ในสถานะเข้าพักแล้ว', 'RESIDENT_INACTIVE'),
            'already_linked' => throw new HttpException(409, 'บัญชีผู้พักผูก LINE อยู่แล้ว กรุณายกเลิกการผูกก่อน', 'LINE_ALREADY_LINKED'),
            'line_in_use' => throw new HttpException(409, 'บัญชี LINE นี้ผูกกับผู้พักรายอื่นแล้ว', 'LINE_ID_IN_USE'),
            default => throw new HttpException(422, 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ', 'LINE_LINK_CODE_INVALID'),
        };
    }

    public function revokePending(int $residentId): void
    {
        $statement = $this->app->database()->pdo()->prepare(
            "UPDATE line_link_codes
                SET status='revoked',revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
              WHERE resident_id=? AND status='pending'",
        );
        $statement->execute([$residentId]);
    }

    private function codeHash(string $code): string
    {
        return hash_hmac('sha256', "line-bind-code\0{$code}", $this->app->config->appKey());
    }
}
