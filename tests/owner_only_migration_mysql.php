<?php
declare(strict_types=1);

// DDL/fault-injection suite. Use ONLY an empty disposable testing schema; do
// not load .env or any configured deployment/provider credentials.
$database = (string) getenv('DB_DATABASE');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || preg_match('/^appj_owner_schema_[a-z0-9_]+$/D', $database) !== 1) {
    fwrite(STDERR, "Refusing owner-only migration test outside its isolated schema\n");
    exit(64);
}
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1')
    . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . $database . ';charset=utf8mb4',
    (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert((int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() === 0,
    'An empty isolated schema is required');
$root = dirname(__DIR__);
require_once $root . '/src/Support/OwnerAccessSchema.php';
$sql = (string) file_get_contents($root . '/database/schema.sql');
$migration = (string) file_get_contents($root . '/database/migrations/017_owner_only_access.sql');
$runSql = static function (string $source) use ($pdo): void {
    $delimiter = ';'; $buffer = '';
    foreach (preg_split('/\R/', $source) as $line) {
        if (preg_match('/^\s*--/', $line) === 1 || trim($line) === '') continue;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') throw new RuntimeException('Unfinished migration SQL');
            $delimiter = $match[1]; continue;
        }
        $buffer .= $line . "\n"; $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter))); $buffer = '';
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $result = $pdo->query($statement);
            do { if ($result->columnCount() > 0) $result->fetchAll(); } while ($result->nextRowset());
            $result->closeCursor();
        } finally { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); }
    }
    if (trim($buffer) !== '') throw new RuntimeException('Unterminated migration SQL');
};
$reject = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (PDOException $error) {
        $assert(str_contains($error->getMessage(), $message), 'Unexpected rejection: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
};
$passed = 0;
$pass = static function (string $name) use (&$passed): void { $passed++; fwrite(STDOUT, 'PASS ' . $name . "\n"); };
$runSql($sql);
$assert(Dormitory\Support\OwnerAccessSchema::errors($pdo) === [], 'Fresh owner schema rejected');
$pdo->exec("INSERT INTO admin_users(id,username,password_hash) VALUES(1,'owner.fixture','owner-hash-preserved')");
$assert($pdo->query('SELECT role FROM admin_users WHERE id=1')->fetchColumn() === 'owner', 'Fresh default must be owner');
$reject(fn() => $pdo->exec("INSERT INTO admin_users(username,password_hash,role) VALUES('bad.role','x','admin')"), 'role');
$pdo->exec("SET sql_mode=''");
$reject(fn() => $pdo->exec("INSERT INTO admin_users(username,password_hash,role) VALUES('bad.coercion','x','admin')"), 'chk_admin_users_owner');
$pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$pass('fresh installation only accepts owner, including permissive SQL mode');

$pdo->exec('ALTER TABLE admin_users ALTER CHECK chk_admin_users_retired NOT ENFORCED');
$assert(Dormitory\Support\OwnerAccessSchema::errors($pdo) !== [], 'Unenforced retirement check passed');
$pdo->exec('ALTER TABLE admin_users ALTER CHECK chk_admin_users_retired ENFORCED');
$pdo->exec('ALTER TABLE admin_users DROP CHECK chk_admin_users_retired, ADD CONSTRAINT chk_admin_users_retired CHECK (retired_at IS NULL OR active IN (0,1))');
$assert(Dormitory\Support\OwnerAccessSchema::errors($pdo) !== [], 'Weak same-name retirement check passed');
$pdo->exec('ALTER TABLE admin_users DROP CHECK chk_admin_users_retired, ADD CONSTRAINT chk_admin_users_retired CHECK (retired_at IS NULL OR active=0)');
$pass('runtime readiness rejects unenforced and weakened retirement guards');

// Reconstruct the exact pre-017 account table; no evidence table is bypassed.
$pdo->exec('DROP TRIGGER trg_admin_users_retirement_immutable');
$pdo->exec('ALTER TABLE admin_users DROP CHECK chk_admin_users_owner, DROP CHECK chk_admin_users_retired, DROP COLUMN retired_at, MODIFY COLUMN role ENUM(\'owner\',\'admin\') NOT NULL DEFAULT \'admin\'');
$pdo->exec("INSERT INTO admin_users(id,username,password_hash,role,auth_version,active,created_by)
    VALUES(2,'staff.active','staff-hash-active','admin',7,1,1),(3,'staff.disabled','staff-hash-disabled','admin',4,0,2)");
$pdo->exec("INSERT INTO audit_logs(actor_type,actor_id,action,entity_type,entity_id,details)
    VALUES('admin',2,'fixture.history','admin_user','2',JSON_OBJECT('preserve',true))");
$auditBefore = $pdo->query('SELECT * FROM audit_logs')->fetchAll();
$ownerBefore = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch();
$pdo->exec("INSERT INTO line_admin_recipients(id,oa_id,label,is_owner,muted_categories,line_user_id,claimed_at,code_hash,code_enc,created_by)
    VALUES(1,0,'Legacy staff',0,JSON_ARRAY(),CONCAT('U',REPEAT('a',32)),UTC_TIMESTAMP(6),REPEAT('a',64),'staff-secret',2),
          (2,0,'Owner contact',1,JSON_ARRAY(),CONCAT('U',REPEAT('b',32)),UTC_TIMESTAMP(6),REPEAT('b',64),'owner-secret',1),
          (3,0,'Admin-created owner invitation',1,JSON_ARRAY(),CONCAT('U',REPEAT('c',32)),UTC_TIMESTAMP(6),REPEAT('c',64),'legacy-owner-secret',2)");
foreach (['pending','processing','sent'] as $index => $status) {
    $id = $index + 1;
    $statement = $pdo->prepare("INSERT INTO line_notice_outbox(id,oa_id,admin_recipient_id,recipient,category,message_enc,retry_key,status,claim_token,lease_until,sent_at)
        VALUES(?,0,1,CONCAT('U',REPEAT('a',32)),'billing','history-enc',?,?,IF(?='processing',REPEAT('c',64),NULL),IF(?='processing',UTC_TIMESTAMP(6)+INTERVAL 120 SECOND,NULL),IF(?='sent',UTC_TIMESTAMP(6),NULL))");
    $statement->execute([$id,'00000000-0000-4000-8000-00000000000'.$id,$status,$status,$status,$status]);
}
$sentBefore = $pdo->query('SELECT * FROM line_notice_outbox WHERE id=3')->fetch();
$pdo->exec("INSERT INTO line_notice_outbox(id,oa_id,admin_recipient_id,recipient,category,message_enc,retry_key)
    VALUES(4,0,3,CONCAT('U',REPEAT('c',32)),'billing','history-enc','00000000-0000-4000-8000-000000000004')");
$ownerContactBefore = $pdo->query('SELECT * FROM line_admin_recipients WHERE id=2')->fetch();

$pdo->exec('UPDATE admin_users SET active=0 WHERE id=1');
$legacyBefore = $pdo->query('SELECT * FROM admin_users')->fetchAll();
$reject(fn() => $runSql($migration), 'DORMITORY_017_CREATE_ACTIVE_OWNER_BEFORE_RETIRING_ADMINS');
$assert($pdo->query('SELECT * FROM admin_users')->fetchAll() === $legacyBefore, 'No-owner preflight mutated legacy accounts');
$assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='admin_users' AND column_name='retired_at'")->fetchColumn() === 0,
    'No-owner preflight added marker column');
$pdo->exec('UPDATE admin_users SET active=1 WHERE id=1');
$ownerBefore = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch();
$pass('legacy admins require an explicit active owner before any migration mutation');

$runSql($migration);
$assert(Dormitory\Support\OwnerAccessSchema::errors($pdo) === [], 'Migrated owner schema rejected');
$ownerAfter = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch();
unset($ownerAfter['retired_at']);
$assert($ownerAfter === $ownerBefore, 'Original owner changed');
foreach ([2 => [8,'staff-hash-active'],3 => [5,'staff-hash-disabled']] as $id => [$version,$hash]) {
    $row = $pdo->query('SELECT * FROM admin_users WHERE id=' . $id)->fetch();
    $assert((int)$row['active'] === 0 && $row['role'] === 'owner' && $row['retired_at'] !== null
        && (int)$row['auth_version'] === $version && $row['password_hash'] === $hash, 'Legacy admin retirement or evidence failed');
}
$assert((int)$pdo->query('SELECT created_by FROM admin_users WHERE id=3')->fetchColumn() === 2, 'Historical FK reference changed');
$assert($pdo->query('SELECT * FROM audit_logs')->fetchAll() === $auditBefore, 'Audit history changed');
$pass('legacy accounts stay inactive, sessions revoke, owners and audit/FK evidence remain');

$reject(fn() => $pdo->exec('UPDATE admin_users SET active=1 WHERE id=2'), 'chk_admin_users_retired');
$reject(fn() => $pdo->exec('UPDATE admin_users SET retired_at=NULL,active=1 WHERE id=2'), 'RETIRED_ACCOUNT_IMMUTABLE');
$reject(fn() => $pdo->exec('UPDATE admin_users SET retired_at=retired_at+INTERVAL 1 SECOND WHERE id=2'), 'RETIRED_ACCOUNT_IMMUTABLE');
$pass('retired accounts cannot reactivate or clear/change the permanent marker');

$staff = $pdo->query('SELECT * FROM line_admin_recipients WHERE id=1')->fetch();
$assert((int)$staff['enabled'] === 0 && $staff['revoked_at'] !== null && $staff['code_enc'] === null && (int)$staff['is_owner'] === 0,
    'Staff LINE invitation not revoked');
$assert($pdo->query('SELECT * FROM line_admin_recipients WHERE id=2')->fetch() === $ownerContactBefore, 'Owner LINE contact changed');
foreach ([1,2,4] as $id) {
    $row=$pdo->query('SELECT * FROM line_notice_outbox WHERE id='.$id)->fetch();
    $assert($row['status']==='failed' && $row['claim_token']===null && $row['lease_until']===null && $row['last_error']==='ADMIN_ROLE_RETIRED',
        'Staff queued/claimed notice still deliverable');
}
$assert($pdo->query('SELECT * FROM line_notice_outbox WHERE id=3')->fetch() === $sentBefore, 'Sent LINE history changed');
$legacyOwnerContact = $pdo->query('SELECT * FROM line_admin_recipients WHERE id=3')->fetch();
$assert((int)$legacyOwnerContact['is_owner']===1 && (int)$legacyOwnerContact['enabled']===0
    && $legacyOwnerContact['revoked_at']!==null && $legacyOwnerContact['code_enc']===null,
    'Legacy admin-created owner invitation must also retire');
$pass('staff and admin-created owner LINE codes revoke, queued/claimed notices fail, owner-created contact and sent evidence remain');

$snapshot = [];
foreach (['admin_users','audit_logs','line_admin_recipients','line_notice_outbox'] as $table) $snapshot[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
$runSql($migration);
foreach ($snapshot as $table=>$rows) $assert($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll()===$rows, 'Migration rerun changed '.$table);
$pass('migration reruns preserve all account, audit and delivery data exactly');
fwrite(STDOUT, $passed . " owner-only migration regression groups passed\n");
