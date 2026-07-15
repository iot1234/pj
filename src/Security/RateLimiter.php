<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Config;
use Dormitory\Database;
use Dormitory\Http\HttpException;
use PDO;

final class RateLimiter
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
    ) {
    }

    public function hit(string $scope, string $identity, int $max, int $windowSeconds, int $blockSeconds = 0): void
    {
        $bucket = hash_hmac('sha256', $scope . ':' . strtolower(trim($identity)), $this->config->appKey());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $retryAfter=$this->database->transaction(function (PDO $pdo) use ($bucket, $max, $windowSeconds, $blockSeconds, $now): ?int {
            // Create the bucket with zero hits atomically. This avoids the
            // missing-row SELECT/INSERT race on simultaneous first requests.
            $ensure=$pdo->prepare('INSERT INTO rate_limits (bucket_key,window_started_at,hits,blocked_until,updated_at) VALUES (?,UTC_TIMESTAMP(),0,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)');
            $ensure->execute([$bucket]);
            $select = $pdo->prepare('SELECT bucket_key, window_started_at, hits, blocked_until FROM rate_limits WHERE bucket_key = ? FOR UPDATE');
            $select->execute([$bucket]);
            $row = $select->fetch();
            if(!$row)throw new \RuntimeException('Rate-limit bucket could not be locked');

            if ($row['blocked_until'] !== null && new \DateTimeImmutable((string) $row['blocked_until'], new \DateTimeZone('UTC')) > $now) {
                return max(1,(new \DateTimeImmutable((string)$row['blocked_until'],new \DateTimeZone('UTC')))->getTimestamp()-$now->getTimestamp());
            }
            $started = new \DateTimeImmutable((string) $row['window_started_at'], new \DateTimeZone('UTC'));
            if (($now->getTimestamp() - $started->getTimestamp()) >= $windowSeconds) {
                $reset = $pdo->prepare('UPDATE rate_limits SET window_started_at=UTC_TIMESTAMP(), hits=1, blocked_until=NULL, updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
                $reset->execute([$bucket]);
                return null;
            }

            $hits = (int) $row['hits'] + 1;
            if ($hits > $max) {
                $seconds = $blockSeconds > 0 ? $blockSeconds : max(1, $windowSeconds - ($now->getTimestamp() - $started->getTimestamp()));
                $block = $pdo->prepare('UPDATE rate_limits SET hits=?, blocked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
                $block->execute([$hits, $seconds, $bucket]);
                return $seconds;
            }
            $update = $pdo->prepare('UPDATE rate_limits SET hits=?, updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
            $update->execute([$hits, $bucket]);
            return null;
        });
        if($retryAfter!==null)throw new HttpException(429,'Too many requests','RATE_LIMITED',['retry_after'=>$retryAfter]);
    }

    public function clear(string $scope, string $identity): void
    {
        $bucket = hash_hmac('sha256', $scope . ':' . strtolower(trim($identity)), $this->config->appKey());
        // Reset in place so the runtime database account never needs DELETE.
        $statement = $this->database->pdo()->prepare('UPDATE rate_limits SET window_started_at=UTC_TIMESTAMP(),hits=0,blocked_until=NULL,updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
        $statement->execute([$bucket]);
    }
}
