<?php
declare(strict_types=1);

namespace Dormitory\Support;

use PDO;

/** Migration 017: former admins are inactive historical rows, never owners. */
final class OwnerAccessSchema
{
    /** @return list<string> */
    public static function errors(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT column_name,column_type,is_nullable,column_default"
            . " FROM information_schema.columns WHERE table_schema=DATABASE()"
            . " AND table_name='admin_users' AND column_name IN ('role','retired_at')")->fetchAll();
        $columns = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $columns[(string) $row['column_name']] = $row;
        }
        $role = $columns['role'] ?? [];
        $retired = $columns['retired_at'] ?? [];
        if (($role['column_type'] ?? '') !== "enum('owner')"
            || ($role['is_nullable'] ?? '') !== 'NO'
            || ($role['column_default'] ?? '') !== 'owner'
            || ($retired['column_type'] ?? '') !== 'datetime(6)'
            || ($retired['is_nullable'] ?? '') !== 'YES'
            || ($retired['column_default'] ?? null) !== null) {
            return ['Migration 017 required: owner-only role and retired_at column must match canonical schema'];
        }
        $statement = $pdo->query("SELECT t.constraint_name,t.enforced,c.check_clause"
            . " FROM information_schema.table_constraints t JOIN information_schema.check_constraints c"
            . " ON c.constraint_schema=t.constraint_schema AND c.constraint_name=t.constraint_name"
            . " WHERE t.constraint_schema=DATABASE() AND t.table_name='admin_users'"
            . " AND t.constraint_type='CHECK' AND t.constraint_name IN ('chk_admin_users_owner','chk_admin_users_retired')");
        $checks = [];
        foreach ($statement->fetchAll() as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            $checks[(string) $row['constraint_name']] = $row;
        }
        $errors = [];
        foreach (['chk_admin_users_owner' => "role='owner'", 'chk_admin_users_retired' => 'retired_atisnulloractive=0'] as $name => $expected) {
            $row = $checks[$name] ?? [];
            if (($row['enforced'] ?? '') !== 'YES'
                || self::normalizedCheck((string) ($row['check_clause'] ?? '')) !== $expected) {
                $errors[] = 'Migration 017 missing or incompatible enforced CHECK: ' . $name;
            }
        }
        if ($errors === [] && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role<>'owner'"
            . " OR (retired_at IS NOT NULL AND active<>0)")->fetchColumn() !== 0) {
            $errors[] = 'Migration 017 contains an invalid active retired account';
        }
        return $errors;
    }

    private static function normalizedCheck(string $expression): ?string
    {
        if ($expression === '' || strlen($expression) > 512) return null;
        // information_schema.check_constraints escapes literal quotes on
        // MySQL 8.4 (for example _utf8mb4\'owner\'); normalize that rendering.
        $expression = strtolower(str_replace(['`', "\\'"], ['', "'"], $expression));
        $expression = preg_replace("/_[a-z0-9]+'owner'/", "'owner'", $expression);
        if (!is_string($expression)
            || (str_contains($expression, "'") && substr_count($expression, "'owner'") !== 1)) return null;
        // These two predicates have no mixed AND/OR grouping; MySQL adds
        // parentheses around each atom and may add a charset to the literal.
        $expression = preg_replace('/[()[:space:]]+/', '', $expression);
        return is_string($expression) ? $expression : null;
    }
}
