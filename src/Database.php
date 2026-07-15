<?php
declare(strict_types=1);

namespace Dormitory;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = $this->config->get('DB_HOST', '127.0.0.1');
        $port = $this->config->int('DB_PORT', 3306);
        $database = $this->config->require('DB_DATABASE');
        $charset = 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ];
        if ($this->config->bool('DB_SSL', false)) {
            $ca = $this->config->require('DB_SSL_CA');
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        $this->pdo = new PDO(
            $dsn,
            $this->config->require('DB_USERNAME'),
            (string) $this->config->get('DB_PASSWORD', ''),
            $options,
        );
        $this->pdo->exec("SET time_zone = '+00:00'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $owns = !$pdo->inTransaction();
        if(!$owns)return $callback($pdo);

        for($attempt=1;$attempt<=3;$attempt++){
            $pdo->beginTransaction();
            try {
                $result = $callback($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if($attempt<3&&$this->retryableTransactionError($error)){
                    usleep(random_int(20_000,80_000)*$attempt);
                    continue;
                }
                throw $error;
            }
        }
        throw new \RuntimeException('Transaction retry loop exhausted');
    }

    private function retryableTransactionError(Throwable $error): bool
    {
        if(!$error instanceof PDOException)return false;
        $driverCode=(int)($error->errorInfo[1]??0);
        return in_array($driverCode,[1205,1213],true)||(string)$error->getCode()==='40001';
    }
}
