<?php
declare(strict_types=1);

namespace Dormitory\Support;

use PDO;

/** Deployment data gate must match the phone login's account/room policy. */
final class ResidentAccessReadiness
{
    public static function countInvalid(PDO $pdo): int
    {
        return (int) $pdo->query(<<<'SQL'
SELECT COUNT(*) FROM residents resident
 WHERE resident.active=1
   AND (
       resident.phone_norm NOT REGEXP '^0[0-9]{9}$'
       OR (
           SELECT COUNT(*) FROM occupancies occupancy
             JOIN rooms room ON room.id=occupancy.room_id AND room.deleted_at IS NULL
            WHERE occupancy.resident_id=resident.id AND occupancy.status='active'
       )<>1
   )
SQL
        )->fetchColumn();
    }
}
