<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use PDO;

final class StayOverviewService
{
    public function __construct(private readonly Application $app) {}

    /** Whole daily inventory and booking counts, independent of paginated tables. */
    public function daily(int $ownerId): array
    {
        $pdo = $this->app->database()->pdo();
        $owner = $pdo->prepare("SELECT id FROM admin_users WHERE id=? AND role='owner' AND active=1 AND retired_at IS NULL");
        $owner->execute([$ownerId]);
        if ($owner->fetchColumn() === false) throw new HttpException(403, 'ต้องเป็นเจ้าของระบบที่เปิดใช้งาน', 'ADMIN_INACTIVE');
        $now = (string) $pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
        $zone = new \DateTimeZone((string) $this->app->config->get('APP_TIMEZONE', 'Asia/Bangkok'));
        $today = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        $statement = $pdo->prepare("SELECT
            COALESCE(SUM(check_in_date=? AND (status='confirmed' OR (status='pending' AND expires_at>?))),0) AS arrivals,
            COALESCE(SUM(status='checked_in' AND check_out_date<=?),0) AS departures,
            COALESCE(SUM(status='pending' AND expires_at>?),0) AS pending,
            COALESCE(SUM(status='checked_in'),0) AS occupied,
            COALESCE(SUM(CASE WHEN status='checked_in' THEN guests ELSE 0 END),0) AS guests
            FROM daily_bookings");
        $statement->execute([$today, $now, $today, $now]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($counts)) throw new \RuntimeException('Cannot read daily overview');
        $counts = array_map(static fn($value): int => (int) $value, $counts);
        $rooms = $this->app->rooms()->all('daily');
        $counts['rooms'] = count($rooms);
        $counts['cleaning'] = count(array_filter($rooms, static fn(array $room): bool => $room['housekeeping_status'] === 'cleaning'));
        return ['type' => 'daily', 'today' => $today, 'generated_at_utc' => $now, 'counts' => $counts];
    }
}
