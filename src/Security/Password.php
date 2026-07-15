<?php
declare(strict_types=1);

namespace Dormitory\Security;

use Dormitory\Http\HttpException;

final class Password
{
    public static function hash(string $plain): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if ($algorithm === PASSWORD_BCRYPT && strlen($plain) > 72) {
            throw new \RuntimeException('Password exceeds the safe bcrypt byte limit');
        }
        $hash = password_hash($plain, $algorithm);
        if (!is_string($hash)) {
            throw new \RuntimeException('Password hashing failed');
        }
        return $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algorithm);
    }

    public static function assertAdmin(string $password, ?string $username = null): void
    {
        $maximumBytes=defined('PASSWORD_ARGON2ID')?200:72;
        if (strlen($password) < 12 || strlen($password) > $maximumBytes) {
            throw new HttpException(422, "รหัสผ่านต้องยาว 12-{$maximumBytes} ไบต์สำหรับระบบเข้ารหัสของเครื่องนี้", 'VALIDATION_ERROR', ['field' => 'password']);
        }
        if ($password !== trim($password) || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new HttpException(422, 'รหัสผ่านห้ามมีช่องว่างหัวท้ายหรืออักขระควบคุม', 'WEAK_PASSWORD', ['field' => 'password']);
        }

        $normalized = mb_strtolower($password, 'UTF-8');
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? '';
        $common = ['password', 'password123', 'admin', 'administrator', 'qwerty', 'letmein', 'changeme', 'welcome123'];
        if (in_array($compact, $common, true)
            || preg_match('/^(.)\1+$/us', $password)
            || ($username !== null && $username !== '' && str_contains($normalized, mb_strtolower($username, 'UTF-8')))) {
            throw new HttpException(422, 'รหัสผ่านเดาง่ายเกินไป กรุณาอย่าใช้ชื่อผู้ใช้หรือคำยอดนิยม', 'WEAK_PASSWORD', ['field' => 'password']);
        }

        $classes = 0;
        foreach (['/\p{Ll}/u', '/\p{Lu}/u', '/\p{Lo}/u', '/\p{N}/u', '/[^\p{L}\p{N}\s]/u'] as $pattern) {
            if (preg_match($pattern, $password)) $classes++;
        }
        $minimumClasses = strlen($password) >= 16 ? 2 : 3;
        if ($classes < $minimumClasses) {
            throw new HttpException(422, 'รหัสผ่านต้องผสมตัวอักษรพิมพ์เล็ก/ใหญ่ ตัวเลข หรือสัญลักษณ์ให้คาดเดายาก', 'WEAK_PASSWORD', ['field' => 'password']);
        }
    }

    public static function assertPin(string $pin): void
    {
        if (!preg_match('/^\d{6,12}$/', $pin)) {
            throw new HttpException(422, 'PIN ต้องเป็นตัวเลข 6-12 หลัก', 'VALIDATION_ERROR', ['field' => 'pin']);
        }
        if (preg_match('/^(\d)\1+$/', $pin)
            || preg_match('/(?:012345|123456|234567|345678|456789|987654|876543|765432|654321)/', $pin)) {
            throw new HttpException(422, 'PIN เดาง่ายเกินไป กรุณาใช้ตัวเลขที่คาดเดายากขึ้น', 'WEAK_PIN', ['field' => 'pin']);
        }
    }
}
