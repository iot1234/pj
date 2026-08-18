<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Refusing migration fixture outside APP_ENV=testing\n");
    exit(64);
}

$database = (string) getenv('DB_DATABASE');
$migration = (string) getenv('FIXTURE_MIGRATION');
if (preg_match('/^appj_final_[a-z0-9_]+$/D', $database) !== 1
    || $migration !== '012_move_in_request_hash.sql') {
    throw new RuntimeException('Unsafe migration fixture target');
}

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3307;dbname=' . $database . ';charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$path = dirname(__DIR__) . '/database/migrations/' . $migration;
$delimiter = ';';
$buffer = '';
$lineNumber = 0;
$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    throw new RuntimeException('Cannot read migration fixture');
}
foreach ($lines as $line) {
    $lineNumber++;
    if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
        if (trim($buffer) !== '') {
            throw new RuntimeException('Unexpected SQL before delimiter at line ' . $lineNumber);
        }
        $delimiter = $match[1];
        continue;
    }
    $buffer .= $line . "\n";
    $trimmed = rtrim($buffer);
    if (!str_ends_with($trimmed, $delimiter)) {
        continue;
    }
    $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
    $buffer = '';
    if ($statement === '' || preg_match('/^--[^\n]*$/', $statement) === 1) {
        continue;
    }
    try {
        $result = $pdo->query($statement);
        do {
            if ($result->columnCount() > 0) {
                $result->fetchAll();
            }
        } while ($result->nextRowset());
        $result->closeCursor();
    } catch (Throwable $error) {
        throw new RuntimeException(
            'Migration fixture failed near line ' . $lineNumber . ': ' . $error->getMessage(),
            0,
            $error,
        );
    }
}
if (trim($buffer) !== '') {
    throw new RuntimeException('Unterminated migration fixture SQL');
}

$shape = $pdo->query("SELECT CONCAT(column_type, ':', character_set_name, ':', collation_name, ':', is_nullable)
    FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='bookings' AND column_name='move_in_request_hash'")->fetchColumn();
$trigger = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.triggers
    WHERE trigger_schema=DATABASE() AND trigger_name='trg_bookings_identity_immutable'")->fetchColumn();
if ($shape !== 'char(64):ascii:ascii_bin:YES' || $trigger !== 1) {
    throw new RuntimeException('Migration fixture postcondition failed');
}
$dbaUser = (string) getenv('FIXTURE_DBA_USER');
$dbaPassword = (string) getenv('FIXTURE_DBA_PASSWORD');
if ($dbaUser !== '') {
    if (preg_match('/^appj_final_dba_[a-z0-9_]+$/D', $dbaUser) !== 1 || strlen($dbaPassword) < 20) {
        throw new RuntimeException('Unsafe schema-audit fixture credentials');
    }
    $account = $pdo->quote($dbaUser) . "@'%'";
    $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($dbaPassword));
    $grantDatabase = '`' . str_replace('_', '\\_', $database) . '`';
    $pdo->exec('GRANT ALL PRIVILEGES ON ' . $grantDatabase . '.* TO ' . $account);
}
fwrite(STDOUT, "PASS migration fixture {$migration} on {$database}\n");
