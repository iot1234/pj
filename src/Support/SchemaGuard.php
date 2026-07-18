<?php
declare(strict_types=1);

namespace Dormitory\Support;

final class SchemaGuard
{
    private const ACTIVE_PHONE_EXPRESSION_PENDING_FIRST = "casewhenstatusin'pending','confirmed'thenphone_normelsenullend";
    private const ACTIVE_PHONE_EXPRESSION_CONFIRMED_FIRST = "casewhenstatusin'confirmed','pending'thenphone_normelsenullend";

    /**
     * Normalize only formatting emitted by MySQL for the exact generated
     * expression. Literal validation happens before formatting is removed so
     * values such as 'pen(ding)' cannot collapse into an allowed expression.
     */
    public static function activePhoneGenerationExpression(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }

        $literalExpression = strtolower(str_replace("\\'", "'", $value));
        $literalExpression = preg_replace("/_[a-z0-9]+'/", "'", $literalExpression);
        if (!is_string($literalExpression)
            || substr_count($literalExpression, "'") !== 4
            || substr_count($literalExpression, "'pending'") !== 1
            || substr_count($literalExpression, "'confirmed'") !== 1) {
            return null;
        }

        $normalized = str_replace([chr(96), '(', ')'], '', $literalExpression);
        $normalized = preg_replace('/[[:space:]]+/', '', $normalized);
        if (!is_string($normalized)
            || !in_array($normalized, [
                self::ACTIVE_PHONE_EXPRESSION_PENDING_FIRST,
                self::ACTIVE_PHONE_EXPRESSION_CONFIRMED_FIRST,
            ], true)) {
            return null;
        }
        return $normalized;
    }
}
