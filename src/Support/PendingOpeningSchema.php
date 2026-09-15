<?php
declare(strict_types=1);

namespace Dormitory\Support;

use PDO;
use RuntimeException;

/** Migration 015 capability and the data states its write guards permit. */
final class PendingOpeningSchema
{
    public const CHECK_NAME = 'chk_occupancies_opening_readings_v2';

    /** @return list<string> */
    public static function errors(PDO $pdo): array
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        if (!is_string($sql) || preg_match(
            '/CONSTRAINT ' . self::CHECK_NAME . '\s+CHECK\s*\((.*?)\n    \),/s',
            $sql,
            $matches,
        ) !== 1) {
            return ['Cannot read canonical migration 015 opening-reading check'];
        }
        $statement = $pdo->prepare(
            "SELECT c.check_clause,t.enforced FROM information_schema.table_constraints t"
            . ' JOIN information_schema.check_constraints c'
            . ' ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name'
            . " WHERE t.constraint_schema=DATABASE() AND t.table_name='occupancies'"
            . " AND t.constraint_type='CHECK' AND t.constraint_name=?"
        );
        $statement->execute([self::CHECK_NAME]);
        $row = $statement->fetch();
        $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
        if (($row['enforced'] ?? '') !== 'YES') {
            return ['Missing enforced migration 015 check: ' . self::CHECK_NAME];
        }
        try {
            if (self::expressionTree($matches[1]) !== self::expressionTree((string) ($row['check_clause'] ?? ''))) {
                return ['Migration 015 opening-reading check differs from canonical schema'];
            }
        } catch (RuntimeException) {
            return ['Migration 015 opening-reading check differs from canonical schema'];
        }
        return [];
    }

    /** @return array{pending:int,invalid:int} */
    public static function missingOpeningCounts(PDO $pdo): array
    {
        $row = $pdo->query(<<<'SQL'
SELECT
    COALESCE(SUM(status='active' AND both_missing=1 AND has_history=0),0) AS pending,
    COALESCE(SUM(both_missing=0 OR has_history=1),0) AS invalid
FROM (
    SELECT o.status,
           (o.opening_water_reading IS NULL AND o.opening_electric_reading IS NULL) AS both_missing,
           (
               EXISTS(SELECT 1 FROM meter_readings m
                   WHERE m.occupancy_id=o.id OR (
                       m.room_id=o.room_id
                       AND m.period>=DATE_FORMAT(o.move_in_date,'%Y-%m-01')
                       AND m.period<=DATE_FORMAT(COALESCE(o.move_out_date,'9999-12-31'),'%Y-%m-01')
                   ))
               OR EXISTS(SELECT 1 FROM bills b
                   WHERE b.occupancy_id=o.id OR (
                       b.room_id=o.room_id
                       AND b.period>=DATE_FORMAT(o.move_in_date,'%Y-%m-01')
                       AND b.period<=DATE_FORMAT(COALESCE(o.move_out_date,'9999-12-31'),'%Y-%m-01')
                   ))
           ) AS has_history
    FROM occupancies o
    WHERE o.opening_water_reading IS NULL OR o.opening_electric_reading IS NULL
) missing_openings
SQL
        )->fetch();
        return ['pending' => (int) $row['pending'], 'invalid' => (int) $row['invalid']];
    }

    /**
     * MySQL adds redundant parentheses and identifier quotes. Parse this small
     * AND/OR predicate grammar so formatting can vary without discarding the
     * operator grouping that keeps partial NULL pairs from passing a CHECK.
     *
     * @return array<mixed>
     */
    private static function expressionTree(string $expression): array
    {
        $expression = strtolower(str_replace('`', '', $expression));
        if (strlen($expression) > 4096 || preg_match('/[^a-z0-9_\s().<>=]/', $expression)) {
            throw new RuntimeException('Unexpected check expression');
        }
        preg_match_all('/[a-z_][a-z0-9_]*|\d+(?:\.\d+)?|>=|<=|[()]/', $expression, $matches);
        $tokens = $matches[0];
        if (preg_replace('/\s+/', '', $expression) !== implode('', $tokens)) {
            throw new RuntimeException('Unexpected check expression');
        }
        $position = 0;
        $parseAtom = static function () use (&$parseAtom, &$parseOr, &$position, $tokens): array {
            if (($tokens[$position] ?? null) === '(') {
                $position++;
                $result = $parseOr();
                if (($tokens[$position++] ?? null) !== ')') throw new RuntimeException('Unbalanced check');
                return $result;
            }
            $column = $tokens[$position++] ?? '';
            if (!in_array($column, ['opening_water_reading', 'opening_electric_reading'], true)) {
                throw new RuntimeException('Unexpected check column');
            }
            $operator = $tokens[$position++] ?? '';
            if ($operator === 'is') {
                $negative = ($tokens[$position] ?? null) === 'not';
                if ($negative) $position++;
                if (($tokens[$position++] ?? null) !== 'null') throw new RuntimeException('Unexpected null predicate');
                return [$column, $negative ? 'is not null' : 'is null'];
            }
            $number = $tokens[$position++] ?? '';
            if (!in_array($operator, ['>=', '<='], true) || preg_match('/^\d+(?:\.\d+)?$/D', $number) !== 1) {
                throw new RuntimeException('Unexpected bound predicate');
            }
            return [$column, $operator, (float) $number];
        };
        $parseAnd = static function () use (&$position, $tokens, $parseAtom): array {
            $result = $parseAtom();
            while (($tokens[$position] ?? null) === 'and') {
                $position++;
                $result = ['and', $result, $parseAtom()];
            }
            return $result;
        };
        $parseOr = static function () use (&$position, $tokens, $parseAnd): array {
            $result = $parseAnd();
            while (($tokens[$position] ?? null) === 'or') {
                $position++;
                $result = ['or', $result, $parseAnd()];
            }
            return $result;
        };
        $result = $parseOr();
        if ($position !== count($tokens)) throw new RuntimeException('Unexpected trailing check tokens');
        return $result;
    }
}
