<?php
declare(strict_types=1);

namespace Dormitory\Support;

use PDOException;

final class MySqlError
{
    public static function isDuplicateKey(PDOException $error, string ...$expectedKeys): bool
    {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
            return false;
        }

        $driverMessage = (string) ($error->errorInfo[2] ?? '');
        if ($driverMessage === '') {
            $driverMessage = $error->getMessage();
        }

        if (preg_match('/\bfor key\s+(?:[\'"`]([^\'"`]+)[\'"`]|([A-Za-z0-9_.]+))/i', $driverMessage, $match) !== 1) {
            return false;
        }

        $reportedKey = (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
        $qualifiedParts = explode('.', $reportedKey);
        $reportedKey = (string) end($qualifiedParts);

        foreach ($expectedKeys as $expectedKey) {
            if ($expectedKey !== '' && strcasecmp($reportedKey, $expectedKey) === 0) {
                return true;
            }
        }

        return false;
    }
}
