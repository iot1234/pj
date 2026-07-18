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
        $bucket = $this->bucketKey($scope,$identity);
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
                // Keep blocked and fresh buckets on a similar locked-update
                // path without changing hits or extending blocked_until.
                $touch=$pdo->prepare('UPDATE rate_limits SET updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
                $touch->execute([$bucket]);
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
        if($retryAfter!==null)throw new HttpException(429,'มีการทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่','RATE_LIMITED',['retry_after'=>$retryAfter]);
    }

    /**
     * Serialize a business identity inside the caller's transaction without
     * consuming quota. This is used before availability/idempotency reads so
     * concurrent requests for the same phone cannot race those reads.
     */
    public function lockBucket(string $scope,string $identity): void
    {
        $pdo=$this->database->pdo();
        if(!$pdo->inTransaction())throw new \LogicException('Rate-limit bucket locks require an active transaction');
        $bucket=$this->bucketKey($scope,$identity);
        $ensure=$pdo->prepare('INSERT INTO rate_limits (bucket_key,window_started_at,hits,blocked_until,updated_at) VALUES (?,UTC_TIMESTAMP(),0,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE bucket_key=VALUES(bucket_key)');
        $ensure->execute([$bucket]);
        $lock=$pdo->prepare('SELECT bucket_key FROM rate_limits WHERE bucket_key=? FOR UPDATE');
        $lock->execute([$bucket]);
        if($lock->fetchColumn()===false)throw new \RuntimeException('Rate-limit bucket could not be locked');
    }

    /** Undo a successful hit when a later quota in the same transaction denies the operation. */
    public function refundHit(string $scope,string $identity): void
    {
        $pdo=$this->database->pdo();
        if(!$pdo->inTransaction())throw new \LogicException('Rate-limit refunds require an active transaction');
        // If this was the first hit in a newly reset window, expiring the
        // empty bucket makes the next real success establish its own window.
        // Otherwise a denied operation could anchor a future boundary burst.
        $statement=$pdo->prepare("UPDATE rate_limits
            SET window_started_at=CASE WHEN hits<=1 THEN '1970-01-01 00:00:00.000000' ELSE window_started_at END,
                hits=hits-1,updated_at=UTC_TIMESTAMP()
            WHERE bucket_key=? AND blocked_until IS NULL AND hits>0");
        $statement->execute([$this->bucketKey($scope,$identity)]);
        if($statement->rowCount()!==1)throw new \RuntimeException('Rate-limit hit could not be refunded safely');
    }

    public function clear(string $scope, string $identity): void
    {
        $bucket = $this->bucketKey($scope,$identity);
        // Reset in place so the runtime database account never needs DELETE.
        $statement = $this->database->pdo()->prepare('UPDATE rate_limits SET window_started_at=UTC_TIMESTAMP(),hits=0,blocked_until=NULL,updated_at=UTC_TIMESTAMP() WHERE bucket_key=?');
        $statement->execute([$bucket]);
    }

    private function bucketKey(string $scope,string $identity): string
    {
        return hash_hmac('sha256',$scope.':'.strtolower(trim($identity)),$this->config->appKey());
    }
}
