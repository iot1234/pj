<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\SecretCipher;
use Dormitory\Support\MySqlError;
use Dormitory\Support\Validator;
use PDO;
use PDOException;

/** Verified LINE accounts and single-use invitations for the current occupancy. */
final class LineRoomBindingService
{
    private const CODE_PATTERN = '/^BIND-[A-F0-9]{32}$/D';
    private const USER_PATTERN = '/^U[0-9a-f]{32}$/D';
    private readonly SecretCipher $cipher;

    public function __construct(private readonly Application $app)
    {
        $this->cipher = new SecretCipher($app->config);
    }

    /** @return array{rows:array,counts:array} */
    public function overview(): array
    {
        return $this->app->database()->transaction(function (PDO $pdo): array {
            $rows = $pdo->query("SELECT r.id AS resident_id,r.full_name,r.phone_norm AS phone,r.line_user_id,
                    o.id AS occupancy_id,rm.room_code,COALESCE(p.blocked,0) AS blocked
                FROM residents r
                JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
                JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
                LEFT JOIN line_room_policies p ON p.resident_id=r.id
                WHERE r.active=1 ORDER BY rm.room_code,r.id")->fetchAll();
            $legacyProofs = $this->app->notifications()->verifiedLineBindings(array_map(
                static fn(array $row): array => ['resident_id' => (int) $row['resident_id'], 'line_user_id' => $row['line_user_id']], $rows));
            $totals = [];
            foreach ($this->bindingRows() as $binding) {
                if (!$this->current($binding)) continue;
                $id = (int) $binding['resident_id'];
                if ($binding['status'] === 'pending' && (int) $binding['code_live'] === 1) {
                    $totals[$id]['pending'] = ($totals[$id]['pending'] ?? 0) + 1;
                } elseif ($binding['status'] === 'bound' && $this->proofMatches($binding)) {
                    $totals[$id]['bound'] = ($totals[$id]['bound'] ?? 0) + 1;
                }
            }
            $counts = ['total' => count($rows), 'bound' => 0, 'pending' => 0, 'unbound' => 0, 'blocked' => 0, 'bound_accounts' => 0];
            foreach ($rows as &$row) {
                $id = (int) $row['resident_id'];
                $row['resident_id'] = $id;
                $row['blocked'] = (bool) $row['blocked'];
                $row['bound_count'] = $row['blocked'] ? 0 : (($totals[$id]['bound'] ?? 0) + (($legacyProofs[$id] ?? false) ? 1 : 0));
                $row['pending_count'] = $row['blocked'] ? 0 : ($totals[$id]['pending'] ?? 0);
                $state = $row['blocked'] ? 'blocked' : ($row['pending_count'] > 0 ? 'pending' : ($row['bound_count'] > 0 ? 'bound' : 'unbound'));
                $row['line_binding_status'] = $state;
                $counts[$state]++;
                $counts['bound_accounts'] += $row['bound_count'];
                unset($row['line_user_id'], $row['occupancy_id']);
            }
            unset($row);
            return ['rows' => $rows, 'counts' => $counts];
        });
    }

    public function detail(int $residentId): array
    {
        $this->positiveId($residentId);
        return $this->app->database()->transaction(function (PDO $pdo) use ($residentId): array {
            $resident = $this->resident($residentId, false);
            $policy = $this->policy($residentId);
            $pending = []; $bound = []; $history = [];
            foreach ($this->bindingRows($residentId) as $binding) {
                $current = $this->current($binding);
                $effective = (string) $binding['status'];
                if ($effective === 'pending') {
                    if (!$current || $policy['blocked']) $effective = 'revoked';
                    elseif ((int) $binding['code_live'] !== 1) $effective = 'expired';
                }
                $row = $this->safeRow($binding);
                $row['status'] = $effective;
                $history[] = $row;
                if ($effective === 'pending' && is_string($binding['code_enc']) && $binding['code_enc'] !== '') {
                    $code = $this->cipher->decrypt($binding['code_enc'], $this->codeField((int) $binding['id']));
                    if (preg_match(self::CODE_PATTERN, $code) !== 1 || !hash_equals($binding['code_hash'], $this->codeHash($code))) {
                        throw new \RuntimeException('Stored LINE invitation failed validation');
                    }
                    $pending[] = $row + ['code' => $code] + $this->links($binding, $code);
                } elseif ($effective === 'bound' && $current && !$policy['blocked'] && $this->proofMatches($binding)) {
                    $bound[] = $row;
                }
            }
            $legacy = $policy['blocked'] ? null : $this->legacyAccount($resident);
            if ($legacy !== null) $bound[] = $this->safeLegacyAccount($legacy);
            return [
                'resident_id' => $residentId, 'full_name' => $resident['full_name'], 'phone' => $resident['phone'],
                'room_code' => $resident['room_code'], 'blocked' => $policy['blocked'], 'reason' => $policy['reason'],
                'bound_count' => count($bound), 'pending_count' => count($pending),
                'pending_codes' => $pending, 'bound_accounts' => $bound, 'history' => array_slice($history, 0, 200),
            ];
        });
    }

    /** Safe resident/admin projection; never decrypts an invitation or OA credential. */
    public function status(int $residentId): array
    {
        $this->positiveId($residentId);
        $blocked = $this->isBlocked($residentId);
        $empty = ['line_verified' => false, 'line_user_id_hint' => null, 'line_bound_count' => 0, 'line_blocked' => $blocked];
        if ($blocked) return $empty;
        try { $resident = $this->resident($residentId, false); }
        catch (HttpException $error) {
            if ($error->errorCode === 'RESIDENT_NOT_FOUND') return $empty;
            throw $error;
        }
        $accounts = $this->allCurrentAccounts($resident);
        return ['line_verified' => count($accounts) > 0,
            'line_user_id_hint' => count($accounts) === 1 ? $this->hint($accounts[0]['line_user_id']) : null,
            'line_bound_count' => count($accounts), 'line_blocked' => false];
    }

    public function issue(int $residentId, array $input, int $adminId): array
    {
        $this->positiveId($adminId);
        Validator::only($input, ['ttl_days', 'oa_id', 'replace_pending']);
        $ttl = $this->integer($input['ttl_days'] ?? 7, 'ttl_days', 1, 30);
        $oaId = array_key_exists('oa_id', $input) ? $this->integer($input['oa_id'], 'oa_id', 0, PHP_INT_MAX) : null;
        $replace = $input['replace_pending'] ?? true;
        if (!is_bool($replace)) throw new HttpException(422, 'replace_pending ต้องเป็น true หรือ false', 'VALIDATION_ERROR');
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $adminId, $ttl, $oaId, $replace): array {
            $resident = $this->resident($residentId, true);
            if ($this->isBlocked($residentId)) throw new HttpException(409, 'ผู้พักถูกบล็อกการผูก LINE กรุณาปลดบล็อกก่อน', 'LINE_BINDING_BLOCKED');
            $selected = $oaId ?? $this->app->lineOfficialAccounts()->defaultId();
            $this->availableOa($selected);
            $this->expirePending($residentId);
            if ($replace) {
                $statement = $pdo->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                    WHERE resident_id=? AND status='pending'");
                $statement->execute([$residentId]);
                $this->app->lineBindings()->revokePending($residentId);
            }
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $code = 'BIND-' . strtoupper(bin2hex(random_bytes(16)));
                try {
                    $statement = $pdo->prepare("INSERT INTO line_room_bindings
                        (resident_id,occupancy_id,auth_version,oa_id,code_hash,status,expires_at,created_by,created_at,updated_at)
                        VALUES (?,?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL {$ttl} DAY),?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))");
                    $statement->execute([$residentId, $resident['occupancy_id'], $resident['auth_version'], $selected, $this->codeHash($code), $adminId]);
                    $id = (int) $pdo->lastInsertId();
                    $encrypted = $this->cipher->encrypt($code, $this->codeField($id));
                    $save = $pdo->prepare('UPDATE line_room_bindings SET code_enc=? WHERE id=?');
                    $save->execute([$encrypted, $id]);
                    $this->audit('issue', $residentId, $adminId, ['binding_id' => $id, 'oa_id' => $selected, 'ttl_days' => $ttl, 'replace_pending' => $replace]);
                    $detail = $this->detail($residentId);
                    foreach ($detail['pending_codes'] as $row) {
                        if ($row['id'] === $id) return $row + ['detail' => $detail];
                    }
                    throw new \RuntimeException('New LINE invitation is not readable');
                } catch (PDOException $error) {
                    if (!MySqlError::isDuplicateKey($error, 'uq_line_room_bindings_code_hash') || $attempt === 2) throw $error;
                }
            }
            throw new \RuntimeException('LINE invitation generation exhausted');
        });
    }

    public function revokeCode(int $residentId, int $bindingId, int $adminId): array
    {
        $this->positiveId($bindingId); $this->positiveId($adminId);
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $bindingId, $adminId): array {
            $this->resident($residentId, true);
            $row = $this->lockedBinding($residentId, $bindingId);
            if ($row['status'] !== 'pending') throw new HttpException(409, 'คีย์นี้ไม่ได้อยู่ในสถานะรอผูก กรุณารีเฟรชข้อมูล', 'LINE_BINDING_NOT_PENDING');
            $statement = $pdo->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=? AND status='pending'");
            $statement->execute([$bindingId]);
            $this->audit('revoke_code', $residentId, $adminId, ['binding_id' => $bindingId, 'oa_id' => (int) $row['oa_id']]);
            return $this->detail($residentId);
        });
    }

    public function revokeAccount(int $residentId, int $bindingId, int $adminId): array
    {
        if ($bindingId < 0) throw new HttpException(422, 'บัญชี LINE ไม่ถูกต้อง', 'VALIDATION_ERROR');
        $this->positiveId($adminId);
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $bindingId, $adminId): array {
            $resident = $this->resident($residentId, true);
            if ($bindingId === 0) {
                $account = $this->legacyAccount($resident);
                if ($account === null && empty($resident['line_user_id'])) throw new HttpException(404, 'ไม่พบบัญชี LINE ที่ผูกไว้', 'LINE_BINDING_NOT_FOUND');
                $this->clearLegacy($residentId, $adminId);
            } else {
                $row = $this->lockedBinding($residentId, $bindingId);
                if ($row['status'] !== 'bound') throw new HttpException(409, 'บัญชีนี้ถูกถอนการผูกแล้ว กรุณารีเฟรชข้อมูล', 'LINE_BINDING_NOT_BOUND');
                $account = $this->proofMatches($row) ? $this->recipientRow($row) : null;
                $statement = $pdo->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?");
                $statement->execute([$bindingId]);
            }
            if ($account !== null) $this->app->lineNotices()->enqueueLifecycle($account,
                'ผู้ดูแลยกเลิกการผูก LINE บัญชีนี้แล้ว คุณจะไม่ได้รับบิลหรือแจ้งเตือนของห้องที่เคยผูกไว้ หากต้องการผูกใหม่ กรุณาขอคีย์ใหม่จากผู้ดูแล');
            $this->audit('revoke_account', $residentId, $adminId, ['binding_id' => $bindingId]);
            return $this->detail($residentId);
        });
    }

    public function revokeAll(int $residentId, int $adminId = 0): array
    {
        if ($adminId < 0) throw new HttpException(422, 'รหัสผู้ดูแลไม่ถูกต้อง', 'VALIDATION_ERROR');
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $adminId): array {
            $resident = $this->resident($residentId, true);
            $accounts = $this->allCurrentAccounts($resident);
            $this->revokeNew($residentId);
            $this->clearLegacy($residentId, $adminId);
            foreach ($accounts as $account) $this->app->lineNotices()->enqueueLifecycle($account,
                'ผู้ดูแลยกเลิกการผูก LINE ของห้องที่เคยผูกไว้แล้ว บัญชีนี้จะหยุดรับบิลและแจ้งเตือนจนกว่าจะผูกใหม่ด้วยคีย์จากผู้ดูแล');
            $this->audit('revoke_all', $residentId, $adminId, ['revoked_accounts' => count($accounts)]);
            return $this->detail($residentId);
        });
    }

    public function block(int $residentId, string $reason, int $adminId): array
    {
        $this->positiveId($adminId);
        $reason = Validator::string($reason, 'reason', 0, 500);
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $adminId, $reason): array {
            $resident = $this->resident($residentId, true);
            $accounts = $this->allCurrentAccounts($resident);
            $statement = $pdo->prepare("INSERT INTO line_room_policies(resident_id,blocked,reason,updated_by,updated_at)
                VALUES (?,1,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE blocked=1,reason=VALUES(reason),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP(6)");
            $statement->execute([$residentId, $reason === '' ? null : $reason, $adminId]);
            $this->revokeNew($residentId);
            $this->clearLegacy($residentId, $adminId);
            foreach ($accounts as $account) $this->app->lineNotices()->enqueueLifecycle($account,
                'ผู้ดูแลปิดการผูก LINE ของห้องที่เคยผูกไว้แล้ว บัญชีนี้จะหยุดรับแจ้งเตือนและยังผูกใหม่ไม่ได้ กรุณาติดต่อผู้ดูแลหากต้องการเปิดใช้งานอีกครั้ง');
            $this->audit('block', $residentId, $adminId, ['reason_set' => $reason !== '', 'revoked_accounts' => count($accounts)]);
            return $this->detail($residentId);
        });
    }

    public function unblock(int $residentId, int $adminId): array
    {
        $this->positiveId($adminId);
        return $this->mutate($residentId, function (PDO $pdo) use ($residentId, $adminId): array {
            $this->resident($residentId, true);
            $statement = $pdo->prepare('UPDATE line_room_policies SET blocked=0,reason=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE resident_id=?');
            $statement->execute([$adminId, $residentId]);
            $this->audit('unblock', $residentId, $adminId);
            return $this->detail($residentId);
        });
    }

    public function isBlocked(int $residentId): bool { return $this->policy($residentId)['blocked']; }

    public function consume(string $code, string $lineUserId, int $oaId, int $lockWaitSeconds = 12): array
    {
        $code = strtoupper(LineBotService::normalize($code));
        if (preg_match(self::CODE_PATTERN, $code) !== 1 || preg_match(self::USER_PATTERN, $lineUserId) !== 1 || $oaId < 0) {
            throw new HttpException(422, 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ', 'LINE_LINK_CODE_INVALID');
        }
        $hash = $this->codeHash($code);
        $lookup = $this->app->database()->pdo()->prepare('SELECT resident_id FROM line_room_bindings WHERE code_hash=?');
        $lookup->execute([$hash]); $residentId = $lookup->fetchColumn();
        if ($residentId === false) throw new HttpException(422, 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ', 'LINE_LINK_CODE_INVALID');
        $residentId = (int) $residentId;
        $outcome = $this->mutate($residentId, function (PDO $pdo) use ($hash, $residentId, $lineUserId, $oaId): array {
            $resident = $this->resident($residentId, true);
            $this->availableOa($oaId);
            if ($this->isBlocked($residentId)) throw new HttpException(409, 'บัญชีถูกบล็อกการผูก LINE กรุณาติดต่อผู้ดูแล', 'LINE_BINDING_BLOCKED');
            $statement = $pdo->prepare('SELECT *,expires_at>UTC_TIMESTAMP(6) AS code_live FROM line_room_bindings WHERE code_hash=? FOR UPDATE');
            $statement->execute([$hash]); $row = $statement->fetch();
            if (!$row || (int) $row['resident_id'] !== $residentId || in_array($row['status'], ['revoked', 'expired'], true)) {
                throw new HttpException(422, 'รหัสผูก LINE ไม่ถูกต้องหรือหมดอายุ', 'LINE_LINK_CODE_INVALID');
            }
            if ((int) $row['oa_id'] !== $oaId) throw new HttpException(409, 'รหัสนี้ใช้กับ LINE OA อื่น กรุณาส่งไปที่ OA ที่ผู้ดูแลระบุ', 'LINE_BINDING_WRONG_OA');
            if ((int) $row['occupancy_id'] !== (int) $resident['occupancy_id'] || (int) $row['auth_version'] !== (int) $resident['auth_version']) {
                $this->revokeStale($row);
                return ['error' => new HttpException(409, 'ข้อมูลผู้พักเปลี่ยนแล้ว กรุณาขอคีย์ใหม่จากผู้ดูแล', 'LINE_BINDING_STALE')];
            }
            if ($row['status'] === 'bound') {
                if (!is_string($row['line_user_id']) || !hash_equals($row['line_user_id'], $lineUserId) || !$this->proofMatches($row)) {
                    throw new HttpException(422, 'รหัสนี้ถูกใช้แล้ว กรุณาขอคีย์ใหม่', 'LINE_LINK_CODE_INVALID');
                }
                return $this->consumeResult($row, false);
            }
            if ((int) $row['code_live'] !== 1) {
                $expire = $pdo->prepare("UPDATE line_room_bindings SET status='expired',code_enc=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?");
                $expire->execute([$row['id']]);
                $this->audit('expire', $residentId, 0, ['binding_id' => (int) $row['id']]);
                return ['error' => new HttpException(410, 'รหัสผูก LINE หมดอายุ กรุณาขอรหัสใหม่', 'LINE_LINK_CODE_EXPIRED')];
            }
            $duplicate = $pdo->prepare("SELECT resident_id FROM line_room_bindings WHERE oa_id=? AND line_user_id=? AND status='bound' AND id<>? LIMIT 1 FOR UPDATE");
            $duplicate->execute([$oaId, $lineUserId, $row['id']]);
            $other = $duplicate->fetchColumn();
            if ($other === false && $oaId === 0) {
                $legacy = $pdo->prepare('SELECT id FROM residents WHERE line_user_id=? LIMIT 1 FOR UPDATE');
                $legacy->execute([$lineUserId]); $other = $legacy->fetchColumn();
            }
            if ($other !== false) {
                throw new HttpException(409, (int) $other === $residentId ? 'LINE บัญชีนี้ผูกกับผู้พักรายนี้แล้ว' : 'LINE บัญชีนี้ผูกกับผู้พักรายอื่นแล้ว กรุณาติดต่อผู้ดูแล',
                    (int) $other === $residentId ? 'LINE_ALREADY_LINKED' : 'LINE_ID_IN_USE');
            }
            $row['line_user_id'] = $lineUserId;
            $proof = $this->proofHash($row);
            try {
                $bind = $pdo->prepare("UPDATE line_room_bindings SET status='bound',line_user_id=?,proof_hash=?,code_enc=NULL,bound_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
                    WHERE id=? AND status='pending' AND expires_at>UTC_TIMESTAMP(6)");
                $bind->execute([$lineUserId, $proof, $row['id']]);
                if ($bind->rowCount() !== 1) throw new HttpException(409, 'รหัสเปลี่ยนสถานะระหว่างผูก กรุณาลองใหม่', 'LINE_LINK_CODE_INVALID');
            } catch (PDOException $error) {
                if (MySqlError::isDuplicateKey($error, 'uq_line_room_bindings_oa_user')) throw new HttpException(409, 'LINE บัญชีนี้ผูกไว้แล้ว กรุณาติดต่อผู้ดูแล', 'LINE_ID_IN_USE');
                throw $error;
            }
            $this->audit('bound', $residentId, 0, ['binding_id' => (int) $row['id'], 'oa_id' => $oaId, 'occupancy_id' => (int) $row['occupancy_id']]);
            $adminUrl = rtrim($this->app->config->require('APP_URL'), '/') . '/admin#line-bindings';
            $this->app->lineNotices()->enqueueAdmin('tenancy',
                "มีการผูก LINE ของผู้พักสำเร็จแล้ว กรุณาเปิดระบบเพื่อตรวจสอบ\n" . $adminUrl,
                'binding:' . $row['id'], $oaId);
            return $this->consumeResult($row, true);
        }, $lockWaitSeconds);
        if (isset($outcome['error'])) throw $outcome['error'];
        return $outcome;
    }

    /** @return list<array{id:int,oa_id:int,resident_id:int,occupancy_id:int,line_user_id:string}> */
    public function recipients(int $residentId): array
    {
        $result = [];
        foreach ($this->bindingRows($residentId) as $row) {
            if ($row['status'] === 'bound' && $this->current($row) && !(bool) $row['blocked']
                && (bool) $row['oa_enabled'] && $row['oa_deleted_at'] === null && $this->proofMatches($row)) {
                $result[] = $this->recipientRow($row);
            }
        }
        return $result;
    }

    public function verified(int $bindingId, string $lineUserId, int $oaId): ?array
    {
        if ($bindingId < 1 || $oaId < 0 || preg_match(self::USER_PATTERN, $lineUserId) !== 1) return null;
        $statement = $this->app->database()->pdo()->prepare('SELECT resident_id FROM line_room_bindings WHERE id=?');
        $statement->execute([$bindingId]); $residentId = $statement->fetchColumn();
        if ($residentId === false) return null;
        foreach ($this->recipients((int) $residentId) as $row) {
            if ($row['id'] === $bindingId && $row['oa_id'] === $oaId && hash_equals($row['line_user_id'], $lineUserId)) return $row;
        }
        return null;
    }

    /** Caller owns registry -> resident locks and the identity mutation transaction. */
    public function revokeForIdentity(int $residentId): void
    {
        $this->positiveId($residentId);
        if (!$this->app->database()->pdo()->inTransaction()) throw new \LogicException('Identity revocation requires an active transaction');
        $changed = $this->revokeNew($residentId);
        if ($changed > 0) $this->audit('identity_revoke', $residentId, 0, ['revoked_rows' => $changed]);
    }

    private function mutate(int $residentId, callable $callback, int $wait = 12): mixed
    {
        $this->positiveId($residentId);
        if ($wait < 0 || $wait > 12) throw new \InvalidArgumentException('Invalid LINE lock wait');
        return $this->app->lineOfficialAccounts()->withRegistryLock(
            fn() => $this->app->notifications()->withLineBindingLock($residentId,
                fn() => $this->app->database()->transaction($callback), $wait), $wait);
    }

    private function resident(int $residentId, bool $lock): array
    {
        $statement = $this->app->database()->pdo()->prepare("SELECT r.id,r.full_name,r.phone_norm AS phone,r.auth_version,r.line_user_id,o.id AS occupancy_id,rm.room_code
            FROM residents r JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
            JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            WHERE r.id=? AND r.active=1 ORDER BY o.id DESC LIMIT 2" . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([$residentId]); $rows = $statement->fetchAll();
        if (count($rows) !== 1) throw new HttpException(404, 'ไม่พบผู้พักที่กำลังเข้าพัก', 'RESIDENT_NOT_FOUND');
        return $rows[0];
    }

    private function policy(int $residentId): array
    {
        $statement = $this->app->database()->pdo()->prepare('SELECT blocked,reason FROM line_room_policies WHERE resident_id=?');
        $statement->execute([$residentId]); $row = $statement->fetch();
        return ['blocked' => $row ? (bool) $row['blocked'] : false, 'reason' => $row['reason'] ?? null];
    }

    private function bindingRows(?int $residentId = null): array
    {
        $statement = $this->app->database()->pdo()->prepare("SELECT b.*,b.expires_at>UTC_TIMESTAMP(6) AS code_live,
                r.active AS resident_active,r.auth_version AS current_auth_version,o.id AS current_occupancy_id,
                COALESCE(p.blocked,0) AS blocked,oa.name AS oa_name,oa.basic_id AS oa_basic_id,
                oa.add_friend_url AS oa_add_friend_url,oa.enabled AS oa_enabled,oa.deleted_at AS oa_deleted_at
            FROM line_room_bindings b JOIN residents r ON r.id=b.resident_id
            LEFT JOIN occupancies o ON o.resident_id=r.id AND o.status='active'
            LEFT JOIN rooms rm ON rm.id=o.room_id AND rm.deleted_at IS NULL
            JOIN line_official_accounts oa ON oa.id=b.oa_id
            LEFT JOIN line_room_policies p ON p.resident_id=r.id
            WHERE " . ($residentId === null ? "b.status IN ('pending','bound')" : 'b.resident_id=?') . "
                AND (o.id IS NULL OR rm.id IS NOT NULL) ORDER BY b.id DESC");
        $statement->execute($residentId === null ? [] : [$residentId]);
        return $statement->fetchAll();
    }

    private function current(array $row): bool
    {
        return (int) ($row['resident_active'] ?? 0) === 1
            && (int) ($row['current_occupancy_id'] ?? 0) === (int) $row['occupancy_id']
            && (int) ($row['current_auth_version'] ?? 0) === (int) $row['auth_version'];
    }

    private function proofHash(array $row): string
    {
        $parts = [(int) $row['id'], (int) $row['oa_id'], (int) $row['resident_id'], (int) $row['occupancy_id'], (int) $row['auth_version'], $row['line_user_id']];
        return hash_hmac('sha256', "line-room-binding\0" . implode("\0", $parts), $this->app->config->appKey());
    }

    private function proofMatches(array $row): bool
    {
        return is_string($row['line_user_id'] ?? null) && preg_match(self::USER_PATTERN, $row['line_user_id']) === 1
            && is_string($row['proof_hash'] ?? null) && strlen($row['proof_hash']) === 64
            && hash_equals($this->proofHash($row), $row['proof_hash']);
    }

    private function safeRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'resident_id' => (int) $row['resident_id'], 'occupancy_id' => (int) $row['occupancy_id'],
            'oa_id' => (int) $row['oa_id'], 'oa_name' => (string) $row['oa_name'], 'status' => $row['status'],
            'created_at' => $row['created_at'], 'expires_at' => $row['expires_at'], 'bound_at' => $row['bound_at'],
            'revoked_at' => $row['revoked_at'], 'line_user_id_hint' => $this->hint($row['line_user_id'] ?? null)];
    }

    private function recipientRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'oa_id' => (int) $row['oa_id'], 'resident_id' => (int) $row['resident_id'],
            'occupancy_id' => (int) $row['occupancy_id'], 'line_user_id' => (string) $row['line_user_id']];
    }

    private function consumeResult(array $row, bool $newlyBound): array
    {
        return ['resident_id' => (int) $row['resident_id'], 'occupancy_id' => (int) $row['occupancy_id'],
            'binding_id' => (int) $row['id'], 'oa_id' => (int) $row['oa_id'], 'newly_bound' => $newlyBound];
    }

    private function links(array $row, string $code): array
    {
        $basic = $row['oa_basic_id']; $friend = $row['oa_add_friend_url'];
        if ((int) $row['oa_id'] === 0) {
            $oa = $this->app->lineOfficialAccounts()->get(0);
            $basic = $oa['basic_id'] ?? null; $friend = $oa['line_add_friend_url'] ?? null;
        }
        $links = LineBindingService::officialAccountLinks($basic, $code);
        if (is_string($friend) && $friend !== '') $links['line_add_friend_url'] = $friend;
        return $links;
    }

    private function availableOa(int $oaId): array
    {
        $oa = $this->app->lineOfficialAccounts()->get($oaId);
        if (!($oa['enabled'] ?? false) || ($oa['deleted_at'] ?? null) !== null || !($oa['line_binding_ready'] ?? false)) {
            throw new HttpException(409, 'LINE OA นี้ยังไม่พร้อมผูกบัญชี กรุณาตรวจการตั้งค่า', 'LINE_OA_NOT_AVAILABLE');
        }
        return $oa;
    }

    private function legacyAccount(array $resident): ?array
    {
        $id = (int) $resident['id']; $user = $resident['line_user_id'];
        if (!is_string($user) || !$this->app->notifications()->isLineBindingVerified($id, $user)) return null;
        return ['id' => 0, 'oa_id' => 0, 'resident_id' => $id, 'occupancy_id' => (int) $resident['occupancy_id'], 'line_user_id' => $user];
    }

    private function safeLegacyAccount(array $account): array
    {
        $oa = $this->app->lineOfficialAccounts()->get(0);
        return ['id' => 0, 'oa_id' => 0, 'oa_name' => (string) $oa['name'], 'line_user_id_hint' => $this->hint($account['line_user_id']), 'bound_at' => null, 'legacy' => true];
    }

    private function allCurrentAccounts(array $resident): array
    {
        $result = [];
        foreach ($this->bindingRows((int) $resident['id']) as $row) {
            if ($row['status'] === 'bound' && $this->current($row) && $this->proofMatches($row)) $result[] = $this->recipientRow($row);
        }
        $legacy = $this->legacyAccount($resident);
        if ($legacy !== null) $result[] = $legacy;
        return $result;
    }

    private function lockedBinding(int $residentId, int $bindingId): array
    {
        $statement = $this->app->database()->pdo()->prepare('SELECT * FROM line_room_bindings WHERE id=? AND resident_id=? FOR UPDATE');
        $statement->execute([$bindingId, $residentId]); $row = $statement->fetch();
        if (!$row) throw new HttpException(404, 'ไม่พบคีย์หรือบัญชี LINE ของผู้พักรายนี้', 'LINE_BINDING_NOT_FOUND');
        return $row;
    }

    private function clearLegacy(int $residentId, int $adminId): void
    {
        $statement = $this->app->database()->pdo()->prepare('UPDATE residents SET line_user_id=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=?');
        $statement->execute([$residentId]);
        $this->app->lineBindings()->revokePending($residentId);
        $this->writeAudit('resident.line_unlinked', 'resident', $residentId, $adminId, ['reason' => 'line_room_management']);
    }

    private function revokeNew(int $residentId): int
    {
        $statement = $this->app->database()->pdo()->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6)
            WHERE resident_id=? AND status IN ('pending','bound')");
        $statement->execute([$residentId]); return $statement->rowCount();
    }

    private function revokeStale(array $row): void
    {
        $statement = $this->app->database()->pdo()->prepare("UPDATE line_room_bindings SET status='revoked',code_enc=NULL,revoked_at=UTC_TIMESTAMP(6),updated_at=UTC_TIMESTAMP(6) WHERE id=?");
        $statement->execute([$row['id']]);
        $this->audit('stale_revoke', (int) $row['resident_id'], 0, ['binding_id' => (int) $row['id']]);
    }

    private function expirePending(int $residentId): void
    {
        $statement = $this->app->database()->pdo()->prepare("UPDATE line_room_bindings SET status='expired',code_enc=NULL,updated_at=UTC_TIMESTAMP(6)
            WHERE resident_id=? AND status='pending' AND expires_at<=UTC_TIMESTAMP(6)");
        $statement->execute([$residentId]);
    }

    private function audit(string $action, int $residentId, int $adminId, array $details = []): void
    {
        $this->writeAudit('line.room_binding.' . $action, 'resident', $residentId, $adminId, $details);
    }

    private function writeAudit(string $action, string $entity, int $id, int $adminId, array $details): void
    {
        $request = new Request('POST', '/internal/line-room-bindings', [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], bin2hex(random_bytes(16)));
        $actor = $adminId > 0 ? ['type' => 'admin', 'id' => $adminId] : null;
        $this->app->audit()->writeStrict($request, $actor, $action, $entity, $id, $details);
    }

    private function codeHash(string $code): string { return hash_hmac('sha256', "line-room-invitation\0{$code}", $this->app->config->appKey()); }
    private function codeField(int $id): string { return 'line_room_binding_code_' . $id; }
    private function hint(mixed $id): ?string { return is_string($id) && $id !== '' ? '•••' . substr($id, -6) : null; }
    private function positiveId(int $id): void { if ($id < 1) throw new HttpException(422, 'รหัสรายการไม่ถูกต้อง', 'VALIDATION_ERROR'); }
    private function integer(mixed $value, string $field, int $min, int $max): int
    {
        if ((!is_int($value) && (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1))
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field]);
        }
        return (int) $value;
    }
}
