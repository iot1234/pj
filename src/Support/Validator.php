<?php
declare(strict_types=1);

namespace Dormitory\Support;

use Dormitory\Http\HttpException;

final class Validator
{
    public static function phone(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'เบอร์โทรศัพท์ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'phone']);
        }
        $raw = trim($value);
        $compact = preg_replace('/[\s().-]+/', '', $raw) ?? '';
        if (str_starts_with($compact, '+66')) {
            $compact = '0' . substr($compact, 3);
        } elseif (str_starts_with($compact, '66') && strlen($compact) === 11) {
            $compact = '0' . substr($compact, 2);
        }
        if (!preg_match('/^0\d{9}$/', $compact)) {
            throw new HttpException(422, 'เบอร์โทรศัพท์ไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'phone']);
        }
        return $compact;
    }

    public static function period(mixed $value): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'รอบบิลต้องเป็น YYYY-MM', 'VALIDATION_ERROR', ['field' => 'period']);
        }
        $period = trim($value);
        if (!preg_match('/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/', $period)) {
            throw new HttpException(422, 'รอบบิลต้องเป็น YYYY-MM', 'VALIDATION_ERROR', ['field' => 'period']);
        }
        return $period;
    }

    public static function periodDate(mixed $value): string
    {
        return self::period($value) . '-01';
    }

    public static function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, "{$field} ต้องเป็น YYYY-MM-DD", 'VALIDATION_ERROR', ['field' => $field]);
        }
        $date = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new HttpException(422, "{$field} ต้องเป็น YYYY-MM-DD", 'VALIDATION_ERROR', ['field' => $field]);
        }
        return $date;
    }

    public static function id(mixed $value, string $field = 'id'): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field]);
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field]);
        }
        return (int) $id;
    }

    public static function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        throw new HttpException(422, "{$field} ต้องเป็น boolean", 'VALIDATION_ERROR', ['field' => $field]);
    }

    public static function string(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field]);
        }
        $text = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length < $min || $length > $max) {
            throw new HttpException(422, "{$field} ต้องยาว {$min}-{$max} ตัวอักษร", 'VALIDATION_ERROR', ['field' => $field]);
        }
        return $text;
    }

    public static function nullableEmail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(422, 'อีเมลไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'email']);
        }
        $email = trim($value);
        if ($email === '') {
            return null;
        }
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'อีเมลไม่ถูกต้อง', 'VALIDATION_ERROR', ['field' => 'email']);
        }
        return strtolower($email);
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field, 'allowed' => $allowed]);
        }
        $text = $value;
        if (!in_array($text, $allowed, true)) {
            throw new HttpException(422, "{$field} ไม่ถูกต้อง", 'VALIDATION_ERROR', ['field' => $field, 'allowed' => $allowed]);
        }
        return $text;
    }

    /** @param list<string> $allowed */
    public static function only(array $input, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            throw new HttpException(422, 'พบฟิลด์ที่ไม่อนุญาต', 'UNKNOWN_FIELDS', ['fields' => $unknown]);
        }
    }

    /** Convert an unsigned decimal string to a fixed-scale integer. */
    public static function scaledDecimal(mixed $value, string $field, int $scale = 2, int $maxWholeDigits = 10): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new HttpException(422, "{$field} ต้องเป็นจำนวนไม่ติดลบ", 'VALIDATION_ERROR', ['field' => $field]);
        }
        $raw = trim((string) $value);
        if (!preg_match('/^(\d{1,' . $maxWholeDigits . '})(?:\.(\d{1,' . $scale . '}))?$/', $raw, $match)) {
            throw new HttpException(422, "{$field} ต้องเป็นจำนวนไม่ติดลบ", 'VALIDATION_ERROR', ['field' => $field]);
        }
        $fraction = str_pad($match[2] ?? '', $scale, '0');
        $factor = 10 ** $scale;
        $whole = (int) $match[1];
        if ($whole > intdiv(PHP_INT_MAX, $factor)) {
            throw new HttpException(422, "{$field} มีค่ามากเกินไป", 'VALIDATION_ERROR', ['field' => $field]);
        }
        return $whole * $factor + (int) $fraction;
    }

    public static function decimalString(int $scaled, int $scale = 2): string
    {
        $factor = 10 ** $scale;
        return intdiv($scaled, $factor) . '.' . str_pad((string) ($scaled % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
