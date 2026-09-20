<?php
declare(strict_types=1);

use Dormitory\Domain\LineOfficialAccountService;
use Dormitory\Http\HttpException;
use Dormitory\Http\Request;
use Dormitory\Security\Password;
use Dormitory\Security\SecretCipher;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$database = (string) getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || preg_match('/^(?:appj_line_test_|dormitory_test)[A-Za-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Refusing room LINE integration outside a dedicated testing database\n"); exit(64);
}
/** @var Dormitory\Application $app */
$app = require dirname(__DIR__) . '/bootstrap.php';
$pdo = $app->database()->pdo();
foreach (['admin_users','residents','rooms','bookings','occupancies','bills','payments','line_room_bindings','line_notice_outbox'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) {
        fwrite(STDERR, "Room LINE integration requires a fresh testing database\n"); exit(64);
    }
}
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$expect = static function (callable $callback, string $code) use ($assert): void {
    try { $callback(); } catch (HttpException $error) { $assert($error->errorCode === $code, "Expected {$code}, received {$error->errorCode}"); return; }
    throw new RuntimeException("Expected {$code}");
};
$groups = 0;
$pass = static function (string $message) use (&$groups): void { $groups++; fwrite(STDOUT, "PASS {$message}\n"); };
$row = static function (int $id) use ($pdo): array { $q = $pdo->prepare('SELECT * FROM line_room_bindings WHERE id=?'); $q->execute([$id]); return $q->fetch(); };
$notices = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM line_notice_outbox')->fetchColumn();
$auditCount = static function (string $action) use ($pdo): int { $q = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action=?'); $q->execute([$action]); return (int) $q->fetchColumn(); };
$request = new Request('POST','/tests/line-room-binding',[],[],[],[],['REMOTE_ADDR'=>'127.0.0.24'],'line-room-binding-test');
$q = $pdo->prepare("INSERT INTO admin_users(username,password_hash,role,auth_version,active) VALUES('line_room_owner',?,'owner',1,1)");
$q->execute([Password::hash(bin2hex(random_bytes(24)) . 'Aa1!')]); $ownerId = (int) $pdo->lastInsertId();
$app->settings()->update(['line_basic_id'=>'@roomlegacy','line_channel_access_token'=>'room-legacy-test-token','line_channel_secret'=>'room-legacy-test-secret'], $ownerId);

// An injected bot-info response verifies real credential handling without a network request.
$providerCalls = 0;
$oaService = new LineOfficialAccountService($app, static function (string $token) use (&$providerCalls): array {
    $providerCalls++;
    return match ($token) {
        'room-oa-one-test-token' => ['userId'=>'U' . str_repeat('a',32),'basicId'=>'@roomone'],
        'room-oa-two-test-token' => ['userId'=>'U' . str_repeat('b',32),'basicId'=>'@roomtwo'],
        default => throw new RuntimeException('Unexpected test OA credential'),
    };
});
$oaOne = $oaService->update(0,['channel_access_token'=>'room-oa-one-test-token','channel_secret'=>'room-oa-one-secret','enabled'=>true], $ownerId);
$oaTwo = $oaOne;
(new ReflectionProperty($app,'lineOfficialAccounts'))->setValue($app,$oaService);
$oa1 = (int) $oaOne['id']; $oa2 = (int) $oaTwo['id'];
$oaService->setDefault($oa1, $ownerId);
$today = (new DateTimeImmutable('today',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
$residents = [];
foreach ([1,2,3] as $index) {
    $room = $app->rooms()->create(['room_code'=>'LR-' . $index,'floor'=>1,'room_type'=>'LINE room test','monthly_rent'=>'4500.00']);
    $checkIn = $app->bookings()->createAdminResident($ownerId, ['room_id'=>$room['id'],'full_name'=>'LINE Room Resident ' . $index,'phone'=>'081110000' . $index,
        'move_in_date'=>$today,'opening_water_reading'=>'10.00','opening_electric_reading'=>'20.00','idempotency_key'=>'line-room-checkin-' . $index . '-20260915']);
    $residents[] = (int) $checkIn['resident_id'];
}
[$r1,$r2,$r3] = $residents;
$service = $app->lineRoomBindings();
$user1 = 'U' . str_repeat('1',32); $user2 = 'U' . str_repeat('2',32); $user3 = 'U' . str_repeat('3',32);
$user4 = 'U' . str_repeat('4',32); $legacyUser = 'U' . str_repeat('5',32);
$overview = $service->overview();
$assert(count($overview['rows']) === 3 && $overview['counts']['unbound'] === 3, 'Fresh room overview is incorrect');
foreach ([['ttl_days'=>0],['ttl_days'=>31],['ttl_days'=>1.5],['ttl_days'=>'01'],['replace_pending'=>'false']] as $badInput) {
    $expect(fn() => $service->issue($r1,$badInput,$ownerId), 'VALIDATION_ERROR');
}
$expect(fn() => $service->issue($r1,['line_user_id'=>$user1],$ownerId), 'UNKNOWN_FIELDS');
$expect(fn() => $service->issue(999999,[],$ownerId), 'RESIDENT_NOT_FOUND');
$pass('room overview, input validation and active occupancy scope');

$first = $service->issue($r1,[],$ownerId);
$stored = $row($first['id']);
$assert($first['oa_id'] === $oa1 && preg_match('/^BIND-[0-9A-F]{32}$/D',$first['code']) === 1, 'Default OA invitation is invalid');
$assert($first['line_message_url'] === 'https://line.me/R/oaMessage/%40roomone/?' . $first['code'], 'Invitation draft URL is incorrect');
$assert($stored['code_enc'] !== null && !str_contains(json_encode($stored,JSON_THROW_ON_ERROR),$first['code']), 'Pending invitation is stored as plaintext');
$assert($service->detail($r1)['pending_codes'][0]['code'] === $first['code'], 'Pending invitation cannot be reopened');
$pdo->prepare('UPDATE line_room_bindings SET code_enc=? WHERE id=?')->execute(['unreadable-test-cipher',$first['id']]);
$assert($service->status($r1)['line_verified'] === false,'Safe status tried to use or decrypt a pending invitation');
$pdo->prepare('UPDATE line_room_bindings SET code_enc=? WHERE id=?')->execute([$stored['code_enc'],$first['id']]);
$ttl = $pdo->prepare('SELECT TIMESTAMPDIFF(DAY,created_at,expires_at) FROM line_room_bindings WHERE id=?'); $ttl->execute([$first['id']]);
$assert((int) $ttl->fetchColumn() === 7, 'Default invitation is not valid for seven days');
$expect(fn() => $service->consume($first['code'],$user1,1,0), 'LINE_OA_NOT_FOUND');
$assert($row($first['id'])['status'] === 'pending', 'Wrong OA consumed an invitation');
$pass('encrypted reusable invitation display, default TTL and OA-specific deep link');

$probe = (new Dormitory\Database($app->config))->pdo();
$registryLock = 'dormitory:line-registry:' . substr(hash_hmac('sha256',$database,$app->config->appKey()),0,24);
$residentLock = 'dormitory:line:' . substr(hash_hmac('sha256',(string)$r1,$app->config->appKey()),0,32);
foreach ([$registryLock,$residentLock] as $lock) {
    $q = $probe->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]); $assert((int)$q->fetchColumn() === 1, 'Probe failed to acquire test lock');
    $started = hrtime(true);
    try {
        try { $service->consume($first['code'],$user1,$oa1,0); throw new LogicException('Concurrent lock was bypassed'); }
        catch (HttpException $error) { $assert($lock === $registryLock && $error->errorCode === 'LINE_REGISTRY_BUSY','Wrong registry contention response'); }
        catch (RuntimeException $error) { $assert($lock === $residentLock && $error->getMessage() === 'Could not acquire LINE binding lock','Wrong resident contention response'); }
    } finally { $q = $probe->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
    $assert((hrtime(true)-$started)/1e6 < 1000, 'Webhook zero-wait binding exceeded its lock budget');
    $assert($row($first['id'])['status'] === 'pending', 'Lock contention consumed the invitation');
}
$pass('registry and resident contention respects zero-wait webhook budget');

// Force the actual strict audit INSERT to fail, rather than merely rolling back an outer test transaction.
// The test database owner must preinstall trg_line_room_test_audit_failure:
// BEFORE INSERT ON audit_logs, SIGNAL SQLSTATE '45000' with message
// 'Simulated room binding audit failure' when NEW.action equals
// COALESCE(@line_room_test_fail_audit_action,''). The runtime needs no DDL grants;
// each call below proves that the fixture exists by requiring the INSERT to fail.
$auditFailure = static function (string $action, callable $callback) use ($pdo,$assert): void {
    $q = $pdo->prepare('SET @line_room_test_fail_audit_action=?'); $q->execute([$action]);
    try {
        try { $callback(); } catch (PDOException $error) { $assert(str_contains($error->getMessage(),'Simulated room binding audit failure'), 'Unexpected database failure'); return; }
        throw new RuntimeException('Strict audit failure was not propagated');
    } finally { $pdo->exec('SET @line_room_test_fail_audit_action=NULL'); }
};
$auditFailure('line.room_binding.issue', fn() => $service->issue($r1,[],$ownerId));
$assert($row($first['id'])['status'] === 'pending' && $service->detail($r1)['pending_count'] === 1, 'Failed issuance revoked its predecessor');
$auditFailure('line.room_binding.bound', fn() => $service->consume($first['code'],$user1,$oa1,0));
$assert($row($first['id'])['status'] === 'pending' && $service->recipients($r1) === [], 'Failed binding audit spent the code or left a recipient');
$bound = $service->consume(strtolower($first['code']),$user1,$oa1,0);
$assert($bound['newly_bound'] === true && $bound['binding_id'] === $first['id'], 'Correct invitation could not bind after rollback');
$assert($row($first['id'])['code_enc'] === null && $service->verified($first['id'],$user1,$oa1) !== null, 'Consumed invitation was retained or lacks proof');
$beforeReplay = $auditCount('line.room_binding.bound');
$assert($service->consume($first['code'],$user1,$oa1,0)['newly_bound'] === false, 'Same sender replay is not idempotent');
$assert($auditCount('line.room_binding.bound') === $beforeReplay, 'Replay emitted another bind audit');
foreach ([$registryLock,$residentLock] as $lock) {
    $q = $probe->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]); $acquired = (int)$q->fetchColumn();
    if ($acquired === 1) { $q = $probe->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
    $assert($acquired === 1,'Binding/replay/failure leaked its named lock');
}
$expect(fn() => $service->consume($first['code'],$user2,$oa1,0), 'LINE_LINK_CODE_INVALID');
$assert(!str_contains(json_encode($service->detail($r1),JSON_THROW_ON_ERROR),$first['code']), 'Used invitation appears in history');
$pass('strict audit rollback, atomic proof and same-sender replay');

$second = $service->issue($r1,['replace_pending'=>false],$ownerId);
$third = $service->issue($r1,['oa_id'=>$oa2,'replace_pending'=>false,'ttl_days'=>30],$ownerId);
$assert($service->detail($r1)['pending_count'] === 2 && $service->detail($r1)['bound_count'] === 1, 'Additional keys changed existing bindings');
$assert($third['line_add_friend_url'] === 'https://line.me/R/ti/p/%40roomone', 'Custom official add-friend link was discarded');
$ttl->execute([$third['id']]); $assert((int)$ttl->fetchColumn() === 30,'Thirty-day invitation TTL was not preserved');
$replacement = $service->issue($r1,[],$ownerId);
$assert($row($second['id'])['status'] === 'revoked' && $row($third['id'])['status'] === 'revoked', 'Replace pending did not revoke all old keys');
$assert($service->detail($r1)['bound_count'] === 1 && $service->detail($r1)['pending_count'] === 1, 'Replacing pending dropped a bound account');
$expect(fn() => $service->consume($replacement['code'],$user1,$oa1,0), 'LINE_ALREADY_LINKED');
$service->consume($replacement['code'],$user2,$oa1,0);
$sameUserOtherOa = $service->issue($r1,['oa_id'=>$oa2],$ownerId);
$service->consume($sameUserOtherOa['code'],'U'.str_repeat('e',32),$oa2,0);
$assert(count($service->recipients($r1)) === 3, 'Multiple recipients or same user on another OA is unsupported');
$assert($service->status($r1) === ['line_verified'=>true,'line_user_id_hint'=>null,'line_bound_count'=>3,'line_blocked'=>false], 'Multiple account profile projection is incorrect');
$otherRoom = $service->issue($r2,['oa_id'=>$oa1],$ownerId);
$expect(fn() => $service->consume($otherRoom['code'],$user1,$oa1,0), 'LINE_ID_IN_USE');
$assert($row($otherRoom['id'])['status'] === 'pending', 'Duplicate recipient failure consumed another room invitation');
$service->consume($otherRoom['code'],$user3,$oa1,0);
$pass('multiple keys and accounts, replacement preserves bound accounts, uniqueness is scoped to OA');

$onePending = $service->issue($r1,['replace_pending'=>false],$ownerId);
$twoPending = $service->issue($r1,['oa_id'=>$oa2,'replace_pending'=>false],$ownerId);
$expect(fn() => $service->revokeCode($r2,$onePending['id'],$ownerId), 'LINE_BINDING_NOT_FOUND');
$noticeBefore = $notices();
$service->revokeCode($r1,$onePending['id'],$ownerId);
$assert($service->detail($r1)['pending_count'] === 1 && $service->detail($r1)['bound_count'] === 3, 'Individual code revoke affected other rows');
$assert($notices() === $noticeBefore, 'Code revocation queued a message');
$auditFailure('line.room_binding.revoke_account', fn() => $service->revokeAccount($r1,$replacement['id'],$ownerId));
$assert($row($replacement['id'])['status'] === 'bound' && $notices() === $noticeBefore, 'Failed revocation audit changed the binding or committed its notice');
$service->revokeAccount($r1,$replacement['id'],$ownerId);
$assert($service->detail($r1)['bound_count'] === 2 && $service->detail($r1)['pending_count'] === 1, 'Individual account revoke affected another account/key');
$assert($notices() === $noticeBefore + 1 && $service->verified($replacement['id'],$user2,$oa1) === null, 'Individual revoke failed to stop recipient or queue lifecycle notice');
$expect(fn() => $service->consume($replacement['code'],$user2,$oa1,0), 'LINE_LINK_CODE_INVALID');
$notice = $pdo->query('SELECT * FROM line_notice_outbox ORDER BY id DESC LIMIT 1')->fetch();
$noticeText = (new SecretCipher($app->config))->decrypt($notice['message_enc'],'line_notice_' . str_replace('-','',$notice['retry_key']));
$assert($notice['recipient'] === $user2 && (int) $notice['binding_id'] === $replacement['id'], 'Lifecycle notice lost the revoked destination');
$assert(!str_contains($noticeText,'LINE Room Resident') && !str_contains($noticeText,'LR-') && !str_contains($noticeText,'BIND-'), 'Lifecycle notice includes private room or invitation data');
$pass('individual revoke scope and atomic encrypted generic lifecycle notice');

$expired = $service->issue($r3,[],$ownerId);
$pdo->prepare('UPDATE line_room_bindings SET expires_at=DATE_ADD(created_at,INTERVAL 1 MICROSECOND) WHERE id=?')->execute([$expired['id']]);
$assert($service->detail($r3)['pending_count'] === 0, 'Expired key remains shareable');
$expect(fn() => $service->consume($expired['code'],$user4,$oa1,0), 'LINE_LINK_CODE_EXPIRED');
$assert($row($expired['id'])['status'] === 'expired' && $row($expired['id'])['code_enc'] === null, 'Expiry state did not commit');
$stale = $service->issue($r3,[],$ownerId);
$pdo->prepare('UPDATE residents SET auth_version=auth_version+1 WHERE id=?')->execute([$r3]);
$expect(fn() => $service->consume($stale['code'],$user4,$oa1,0), 'LINE_BINDING_STALE');
$assert($row($stale['id'])['status'] === 'revoked' && $row($stale['id'])['code_enc'] === null, 'Identity-stale key remained usable');
$fresh = $service->issue($r3,[],$ownerId); $service->consume($fresh['code'],$user4,$oa1,0);
$pdo->prepare('UPDATE residents SET auth_version=auth_version+1 WHERE id=?')->execute([$r3]);
$assert($service->recipients($r3) === [] && $service->verified($fresh['id'],$user4,$oa1) === null, 'Stale credential version retained a verified recipient');
$app->lineOfficialAccounts()->withRegistryLock(fn() => $app->notifications()->withLineBindingLock($r3, fn() => $app->database()->transaction(fn() => $service->revokeForIdentity($r3))));
$assert($row($fresh['id'])['status'] === 'revoked', 'Identity hook failed to revoke a completed binding');
$pass('expiry commits, stale identity fails closed and credential changes revoke current delivery');

$forged = $service->issue($r3,[],$ownerId);
$pdo->prepare("UPDATE line_room_bindings SET status='bound',line_user_id=?,proof_hash=?,code_enc=NULL,bound_at=UTC_TIMESTAMP(6) WHERE id=?")
    ->execute([$user4,str_repeat('0',64),$forged['id']]);
$assert($service->recipients($r3) === [] && $service->detail($r3)['bound_count'] === 0 && $service->verified($forged['id'],$user4,$oa1) === null, 'A raw database LINE ID with forged proof became verified');
$expect(fn() => $service->consume($forged['code'],$user4,$oa1,0), 'LINE_LINK_CODE_INVALID');
$beforeForged = $notices(); $service->revokeAccount($r3,$forged['id'],$ownerId);
$assert($notices() === $beforeForged, 'An unverified account received a lifecycle notice');
$pass('raw identity with an invalid proof cannot receive private data or replay a used code');

// Establish a verified legacy fixture to exercise migration compatibility, without creating a new-style proof.
$legacyPending = $app->lineBindings()->issueForAdmin($r2);
$pdo->prepare('UPDATE residents SET line_user_id=? WHERE id=?')->execute([$legacyUser,$r2]);
$app->audit()->writeStrict($request,null,'resident.line_link_verified','resident',$r2,[
    'method'=>'self_service_code','line_user_id_hint'=>'•••555555','line_user_id_hash'=>$app->notifications()->lineBindingHash($r2,$legacyUser),
]);
$legacyAccounts = array_values(array_filter($service->detail($r2)['bound_accounts'],static fn(array $item): bool => $item['id'] === 0));
$assert(count($legacyAccounts) === 1 && $legacyAccounts[0]['oa_id'] === 0, 'Verified legacy account is absent from room detail');
$legacyCollision = $service->issue($r3,['oa_id'=>0],$ownerId);
$expect(fn() => $service->consume($legacyCollision['code'],$legacyUser,0,0), 'LINE_ID_IN_USE');
$service->revokeAccount($r2,0,$ownerId);
$assert($service->detail($r2)['bound_count'] === 1 && count($service->recipients($r2)) === 1, 'Legacy account revoke removed a new account');
$q = $pdo->prepare('SELECT line_user_id FROM residents WHERE id=?'); $q->execute([$r2]); $assert($q->fetchColumn() === null, 'Legacy identity was not cleared');
$q = $pdo->prepare("SELECT COUNT(*) FROM line_link_codes WHERE resident_id=? AND status='pending'"); $q->execute([$r2]); $assert((int) $q->fetchColumn() === 0, 'Legacy pending invitation survived unlink');
$service->consume($legacyCollision['code'],$legacyUser,0,0);
$assert($service->verified($legacyCollision['id'],$legacyUser,0) !== null, 'Released legacy user cannot bind through an OA0 invitation');
$pass('legacy account zero compatibility, duplicate protection and selective unlink');

$beforeBlock = $notices();
$service->block($r1,'ตรวจสอบสิทธิ์ผู้พัก',$ownerId);
$detail = $service->detail($r1);
$assert($detail['blocked'] === true && $detail['bound_count'] === 0 && $detail['pending_count'] === 0 && $service->recipients($r1) === [], 'Blocking did not revoke all room access');
$assert($service->status($r1)['line_blocked'] === true && $service->status($r1)['line_verified'] === false,'Blocked status projection still claims verified access');
$assert($notices() === $beforeBlock + 2, 'Block did not notify each bound account once');
$expect(fn() => $service->issue($r1,[],$ownerId), 'LINE_BINDING_BLOCKED');
$expect(fn() => $app->lineBindings()->issueForAdmin($r1), 'LINE_BINDING_BLOCKED');
$expect(fn() => $service->consume($twoPending['code'],$user2,$oa2,0), 'LINE_BINDING_BLOCKED');
$overview = $service->overview();
$assert($overview['counts']['blocked'] === 1, 'Blocked room is missing from overview counts');
$service->unblock($r1,$ownerId);
$assert(!$service->isBlocked($r1) && $service->detail($r1)['bound_count'] === 0 && $service->detail($r1)['pending_count'] === 0, 'Unblock revived old invitations or accounts');
$reopened = $service->issue($r1,[],$ownerId); $service->consume($reopened['code'],$user2,$oa1,0);
$service->issue($r1,['replace_pending'=>false],$ownerId);
$beforeAll = $notices(); $service->revokeAll($r1,$ownerId);
$assert($service->detail($r1)['bound_count'] === 0 && $service->detail($r1)['pending_count'] === 0 && $notices() === $beforeAll + 1, 'Revoke all left a key/account or omitted notice');
$pass('block/unblock, old-flow enforcement and revoke all without restoration');

$oaService->update($oa1,['enabled'=>false],$ownerId);
$assert($service->recipients($r2) === [] && $service->detail($r2)['bound_count'] === 1, 'Disabled OA delivery or binding management is incorrect');
$assert($service->status($r2) === ['line_verified'=>true,'line_user_id_hint'=>'•••333333','line_bound_count'=>1,'line_blocked'=>false],'Disabled OA erased the administrative bound status');
$expect(fn() => $service->issue($r2,['oa_id'=>$oa1],$ownerId), 'LINE_OA_NOT_AVAILABLE');
$assert($service->detail($r2)['bound_accounts'][0]['line_user_id_hint'] === '•••333333', 'Bound account is not masked');
$assert($providerCalls === 1, 'A room operation unexpectedly tested a provider credential');
foreach ($pdo->query("SELECT details FROM audit_logs WHERE action LIKE 'line.room_binding.%' OR action='resident.line_unlinked'")->fetchAll(PDO::FETCH_COLUMN) as $audit) {
    $assert(!str_contains((string)$audit,'BIND-') && !str_contains((string)$audit,'oaMessage'), 'An invitation leaked to audit');
    foreach ([$user1,$user2,$user3,$user4,$legacyUser] as $user) $assert(!str_contains((string)$audit,$user), 'A raw LINE recipient leaked to audit');
}
$history = $service->detail($r1)['history'];
foreach ($history as $entry) $assert(!array_key_exists('code',$entry) && !array_key_exists('code_enc',$entry) && !array_key_exists('line_user_id',$entry), 'History exposes a credential');
$pass('disabled OA delivery, masked accounts, safe history and secret-free audits');

$oaService->update(0,['enabled'=>true],$ownerId);
// Exercise the real domain transitions with an explicitly claimed administrator.
$adminUser = 'U' . str_repeat('6',32);
$adminRecipient = $app->lineAdminRecipients()->issue(['oa_id'=>$oa2,'label'=>'Event test administrator','is_owner'=>false],$ownerId);
$app->lineOfficialAccounts()->withRegistryLock(fn() => $app->lineAdminRecipients()->consume($adminRecipient['code'],$adminUser,$oa2));
$adminCount = static function (?string $category = null) use ($pdo): int {
    $q = $pdo->prepare('SELECT COUNT(*) FROM line_notice_outbox WHERE admin_recipient_id IS NOT NULL' . ($category === null ? '' : ' AND category=?'));
    $q->execute($category === null ? [] : [$category]); return (int)$q->fetchColumn();
};
$eventRoom = $app->rooms()->create(['room_code'=>'EVENT-1','floor'=>1,'room_type'=>'Events test','monthly_rent'=>'4500.00']);
$bookingInput = ['room_id'=>$eventRoom['id'],'full_name'=>'Private Booking Name','phone'=>'0811120001','idempotency_key'=>'line-admin-event-booking-0001'];
$booking = $app->bookings()->createPublic($bookingInput);
$assert($adminCount('booking') === 1,'Public booking did not enqueue its administrator notice');
$app->bookings()->createPublic($bookingInput);
$assert($adminCount('booking') === 1,'Booking replay duplicated its administrator notice');
$app->bookings()->confirm((int)$booking['id'],$ownerId);
$app->bookings()->cancel((int)$booking['id'],$ownerId,'Private cancellation reason');
$assert($adminCount('booking') === 3,'Booking state transitions did not each enqueue their notice');
$rollbackInput = $bookingInput; $rollbackInput['idempotency_key'] = 'line-admin-event-booking-rollback';
$auditFailure('line.admin.event.test',fn() => $app->database()->transaction(function () use ($app,$rollbackInput,$request): void {
    $created = $app->bookings()->createPublic($rollbackInput);
    $app->audit()->writeStrict($request,null,'line.admin.event.test','booking',(int)$created['id'],[]);
}));
$q = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE idempotency_key=?'); $q->execute([$rollbackInput['idempotency_key']]);
$assert((int)$q->fetchColumn() === 0 && $adminCount('booking') === 3,'Failed booking audit retained its booking or administrator notice');
$app->bookings()->createAdminResident($ownerId,['room_id'=>$eventRoom['id'],'full_name'=>'Private Checkin Name','phone'=>'0811120002','move_in_date'=>$today,
    'opening_water_reading'=>'10.00','opening_electric_reading'=>'20.00','idempotency_key'=>'line-admin-event-checkin-0001']);
$assert($adminCount('tenancy') === 1,'Completed check-in did not enqueue its administrator notice');
$pass('administrator booking/check-in hooks, replay dedupe and audit rollback');

$q = $pdo->prepare("SELECT room_id FROM occupancies WHERE resident_id IN (?,?) AND status='active' ORDER BY room_id"); $q->execute([$r2,$r3]);
$billRooms = array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
$period = substr($today,0,7);
foreach ($billRooms as $roomId) $app->meters()->record(['room_id'=>$roomId,'period'=>$period,'water_current'=>'12.00','electric_current'=>'25.00'],$ownerId);
$app->billing()->updateSettings(['water_rate'=>'18.00','electric_rate'=>'7.00','due_days'=>7],$ownerId);
$billInput = ['room_ids'=>$billRooms,'period'=>$period,'due_date'=>(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'),'confirm_current_period'=>true];
$preview = $app->billing()->preview($billInput); $billInput['preview_token'] = $preview['preview_token'];
$createdBills = $app->billing()->bulk($billInput,$ownerId)['created'];
$assert(count($createdBills) === 2 && $adminCount('billing') === 1,'Bill generation did not emit one notice for its batch');
$batchIds = array_column($createdBills,'id'); sort($batchIds,SORT_NUMERIC);
$app->database()->transaction(fn() => Dormitory\Domain\LineAdminEvents::enqueue($app,'billing.created',hash('sha256',implode(',',$batchIds))));
$assert($adminCount('billing') === 1,'Replayed billing batch event duplicated its notice');
$pass('bill generation emits one stable administrator event per committed batch');

// Reserve/finalize are the database boundary on either side of slip verification.
// Inject a provider decision at that boundary so no upload or provider call is needed.
$reservePayment = new ReflectionMethod(Dormitory\Domain\PaymentService::class,'reserve');
$finalizePayment = new ReflectionMethod(Dormitory\Domain\PaymentService::class,'finalizeReserved');
$billRows = $pdo->query('SELECT id,resident_id,total_amount,status,created_at FROM bills ORDER BY id')->fetchAll();
$paymentToken = bin2hex(random_bytes(32)); $slipDigest = bin2hex(random_bytes(32));
$payment = $reservePayment->invoke($app->payments(),$billRows[0],(int)$billRows[0]['resident_id'],'storage/private/slips/line-event-test.png','image/png',$slipDigest,$paymentToken);
$paymentId = (int)$payment['id'];
$assert($adminCount('payment') === 1,'Payment reservation did not notify administrators');
$reservePayment->invoke($app->payments(),$billRows[0],(int)$billRows[0]['resident_id'],'storage/private/slips/line-event-test.png','image/png',$slipDigest,$paymentToken);
$assert($adminCount('payment') === 1,'Payment upload replay duplicated its notice');
$verifiedDecision = ['decision'=>'verified','provider'=>'test','transaction_ref'=>'PRIVATE-TRANSACTION-REFERENCE','receiver_ref'=>'PRIVATE-RECEIVER','payload'=>[]];
$auditFailure('line.payment.event.test',fn() => $finalizePayment->invoke($app->payments(),$paymentId,(int)$billRows[0]['id'],$paymentToken,$verifiedDecision,
    function (array $data) use ($app,$request): void { $app->audit()->writeStrict($request,null,'line.payment.event.test','payment',(int)$data['id'],[]); }));
$q = $pdo->prepare('SELECT status FROM payments WHERE id=?'); $q->execute([$paymentId]);
$assert($q->fetchColumn() === 'pending' && $adminCount('payment') === 1,'Failed verification audit changed payment or committed its result notice');
$finalizePayment->invoke($app->payments(),$paymentId,(int)$billRows[0]['id'],$paymentToken,$verifiedDecision);
$finalizePayment->invoke($app->payments(),$paymentId,(int)$billRows[0]['id'],$paymentToken,$verifiedDecision);
$assert($adminCount('payment') === 2,'Verified payment was not notified once');
$paymentToken2 = bin2hex(random_bytes(32));
$payment2 = $reservePayment->invoke($app->payments(),$billRows[1],(int)$billRows[1]['resident_id'],'storage/private/slips/line-event-test-2.png','image/png',bin2hex(random_bytes(32)),$paymentToken2);
$finalizePayment->invoke($app->payments(),(int)$payment2['id'],(int)$billRows[1]['id'],$paymentToken2,['decision'=>'pending','provider'=>'test','reason'=>'Private verification reason','payload'=>[]]);
$app->payments()->closePending((int)$payment2['id'],['reason'=>'Private closure reason']);
$assert($adminCount('payment') === 5,'Pending review or administrator closure omitted its payment notice');
$pass('payment submitted/review/verified/rejected events, replay and verification audit rollback');

$app->lineAdminRecipients()->update((int)$adminRecipient['id'],['muted_categories'=>['booking']],$ownerId);
$mutedRoom = $app->rooms()->create(['room_code'=>'EVENT-2','floor'=>1,'room_type'=>'Events test','monthly_rent'=>'4500.00']);
$app->bookings()->createPublic(['room_id'=>$mutedRoom['id'],'full_name'=>'Private Muted Booking','phone'=>'0811120003','idempotency_key'=>'line-admin-event-muted-0001']);
$assert($adminCount('booking') === 3,'Muted booking category still enqueued an administrator notice');
$forbidden = ['Private Booking Name','Private Checkin Name','Private Muted Booking','0811120001','4500.00','PRIVATE-TRANSACTION','PRIVATE-RECEIVER','Private verification','Private closure','BIND-',$adminUser];
foreach ($pdo->query('SELECT * FROM line_notice_outbox WHERE admin_recipient_id IS NOT NULL')->fetchAll() as $adminNotice) {
    $body = (new SecretCipher($app->config))->decrypt($adminNotice['message_enc'],'line_notice_' . str_replace('-','',$adminNotice['retry_key']));
    $assert(str_contains($body,rtrim($app->config->require('APP_URL'),'/') . '/admin#'),'Administrator notice lacks its authenticated console link');
    foreach ($forbidden as $secret) $assert(!str_contains($body,$secret),'A generic administrator notice disclosed private data');
}
$assert($providerCalls === 2,'Domain events unexpectedly contacted an OA provider');
$pass('administrator mute categories and generic authenticated links without private data');

// The DBA-installed notice fixture signals only when this test session sets
// @line_room_test_fail_notice_insert=1; production migrations contain no test hooks.
$deleteTargetBound = $service->issue($r1,['oa_id'=>$oa2,'replace_pending'=>false],$ownerId);
$noticeBeforeBind = $adminCount('tenancy'); $auditBeforeBind = $auditCount('line.room_binding.bound');
$pdo->exec('SET @line_room_test_fail_notice_insert=1');
try {
    try { $service->consume($deleteTargetBound['code'],'U' . str_repeat('7',32),$oa2,0); throw new LogicException('Notice failure did not abort binding'); }
    catch (PDOException $error) { $assert(str_contains($error->getMessage(),'Simulated room binding notice failure'),'Unexpected notice failure'); }
} finally { $pdo->exec('SET @line_room_test_fail_notice_insert=NULL'); }
$assert($row($deleteTargetBound['id'])['status'] === 'pending' && $row($deleteTargetBound['id'])['code_enc'] !== null,'Failed notice INSERT consumed the invitation');
$assert($auditCount('line.room_binding.bound') === $auditBeforeBind && $adminCount('tenancy') === $noticeBeforeBind,'Failed notice INSERT committed a partial audit or notice');
$service->consume($deleteTargetBound['code'],'U' . str_repeat('7',32),$oa2,0);
$service->consume($deleteTargetBound['code'],'U' . str_repeat('7',32),$oa2,0);
$assert($adminCount('tenancy') === $noticeBeforeBind + 1,'Successful binding recovery did not notify its OA administrator once');
$pass('binding, proof audit and OA administrator notice commit or roll back together');

// The sole bot cannot be deleted or replaced accidentally. Bound identities remain intact.
$keepBound = $service->issue($r1,['replace_pending'=>false],$ownerId);
$service->consume($keepBound['code'],'U'.str_repeat('8',32),0,0);
$keepPending = $service->issue($r1,['replace_pending'=>false],$ownerId);
$before=$service->detail($r1); $countBefore=$notices();
$expect(fn()=>$oaService->remove(0,$ownerId),'LINE_SINGLE_BOT_ONLY');
$expect(fn()=>$oaService->create([],$ownerId),'LINE_SINGLE_BOT_ONLY');
$expect(fn()=>$service->issue($r1,['oa_id'=>1],$ownerId),'LINE_SINGLE_BOT_ONLY');
$assert($service->detail($r1)===$before && $notices()===$countBefore,'Rejected bot replacement changed room or notice data');
$pass('single bot deletion and replacement are rejected without changing recipients or codes');
$auditFailure('line.oa_updated',fn()=>$oaService->update(0,['enabled'=>false],$ownerId));
$assert($oaService->get(0)['enabled']===true && $row($keepPending['id'])['status']==='pending','Failed pause audit partially changed bot state');
$oaService->update(0,['enabled'=>false],$ownerId);
$assert($service->recipients($r1)===[] && $service->detail($r1)['bound_count']===$before['bound_count'],'Pause erased bindings or allowed delivery');
$expect(fn()=>$oaService->credentials(0),'LINE_OA_DISABLED');
$oaService->update(0,['enabled'=>true],$ownerId);
$assert($row($keepBound['id'])['status']==='bound' && $row($keepPending['id'])['status']==='pending','Resume changed existing binding identity');
$assert($row($replacement['id'])['status']==='revoked','Resume restored a revoked account');
$pass('pause is audit-atomic and resume keeps only still-authorized accounts');
// Preserve and selectively revoke the legacy user independently of normalized accounts.
$legacyDeleteUser='U'.str_repeat('c',32);
$legacy=$app->lineBindings()->issueForAdmin($r1);
$app->lineBindings()->consumeSerialized($legacy['code'],$legacyDeleteUser,function(array $binding)use($app,$request):void{
 $app->audit()->writeStrict($request,null,'resident.line_link_verified','resident',(int)$binding['resident_id'],['line_user_id_hash'=>$app->notifications()->lineBindingHash((int)$binding['resident_id'],$binding['line_user_id'])]);
});
$expect(fn()=>$oaService->remove(0,$ownerId),'LINE_SINGLE_BOT_ONLY');
$assert($app->notifications()->isLineBindingVerified($r1,$legacyDeleteUser),'Rejected deletion cleared legacy proof');
$auditFailure('resident.line_unlinked',fn()=>$service->revokeAccount($r1,0,$ownerId));
$assert($app->notifications()->isLineBindingVerified($r1,$legacyDeleteUser),'Failed legacy unlink lost proof');
$service->revokeAccount($r1,0,$ownerId);
$assert(!$app->notifications()->isLineBindingVerified($r1,$legacyDeleteUser) && $row($keepBound['id'])['status']==='bound','Selective legacy unlink affected another account');
foreach([$registryLock,$residentLock]as$lock){$q=$probe->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);$assert((int)$q->fetchColumn()===1,'Leaked named lock');$q=$probe->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
$pass('legacy proof is preserved by rejected deletion and independently revocable with rollback');
fwrite(STDOUT,"PASS LINE room binding MySQL: {$groups} groups; no provider requests\n");
