<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Refusing fixture import outside APP_ENV=testing\n");
    exit(64);
}

function importSql(PDO $pdo, string $path): void
{
    $delimiter = ';';
    $buffer = '';
    $lineNumber = 0;
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    foreach ($lines as $line) {
        $lineNumber++;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Unexpected SQL before delimiter at ' . $path . ':' . $lineNumber);
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
            if (preg_match('/^EXECUTE\s+/i', $statement) === 1) {
                $result = $pdo->query($statement);
                do {
                    if ($result->columnCount() > 0) {
                        $result->fetchAll();
                    }
                } while ($result->nextRowset());
                $result->closeCursor();
            } else {
                $pdo->exec($statement);
            }
        } catch (Throwable $error) {
            throw new RuntimeException(
                'SQL import failed near ' . $path . ':' . $lineNumber . ': ' . $error->getMessage(),
                0,
                $error,
            );
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Unterminated SQL in ' . $path);
    }
}

$database = (string) getenv('DB_DATABASE');
if (preg_match('/^appj_final_[a-z0-9_]+$/D', $database) !== 1) {
    throw new RuntimeException('Unsafe testing database name');
}
$root = new PDO(
    'mysql:host=127.0.0.1;port=3307;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$exists = $root->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
$exists->execute([$database]);
if ((int) $exists->fetchColumn() !== 0) {
    throw new RuntimeException('Testing database already exists');
}
$root->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3307;dbname=' . $database . ';charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
importSql($pdo, dirname(__DIR__) . '/database/schema.sql');
importSql($pdo, dirname(__DIR__) . '/database/defaults.sql');
$runtimeUser = (string) getenv('FIXTURE_DB_USER');
$runtimePassword = (string) getenv('FIXTURE_DB_PASSWORD');
if (preg_match('/^appj_final_[a-z0-9_]+$/D', $runtimeUser) !== 1 || strlen($runtimePassword) < 20) {
    throw new RuntimeException('Unsafe runtime fixture credentials');
}
$account = $root->quote($runtimeUser) . "@'%'";
$root->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $root->quote($runtimePassword));
$grantDatabase = '`' . str_replace('_', '\\_', $database) . '`';
$root->exec('GRANT SELECT, INSERT, UPDATE ON ' . $grantDatabase . '.* TO ' . $account);
$tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
$triggers = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn();
fwrite(STDOUT, "database={$database} tables={$tables} triggers={$triggers}\n");
