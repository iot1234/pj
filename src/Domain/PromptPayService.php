<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Http\HttpException;
use Dormitory\Support\Validator;

final class PromptPayService
{
    public static function payload(string $target, string $amount): string
    {
        $digits = preg_replace('/\D+/', '', $target) ?? '';
        if (preg_match('/^0\d{9}$/', $digits)) {
            $account = self::tlv('01', '0066' . substr($digits, 1));
        } elseif (preg_match('/^\d{13}$/', $digits)) {
            $account = self::tlv('02', $digits);
        } else {
            throw new HttpException(422, 'PromptPay target must be a Thai phone number or 13-digit tax ID', 'PROMPTPAY_TARGET_INVALID');
        }
        $sats = Validator::scaledDecimal($amount, 'amount', 2, 12);
        if ($sats <= 0) throw new HttpException(422, 'PromptPay amount must be positive', 'AMOUNT_INVALID');
        $merchant = self::tlv('00', 'A000000677010111') . $account;
        $body = self::tlv('00', '01')
            . self::tlv('01', '12')
            . self::tlv('29', $merchant)
            . self::tlv('58', 'TH')
            . self::tlv('53', '764')
            . self::tlv('54', Validator::decimalString($sats));
        $forCrc = $body . '6304';
        return $forCrc . self::crc16($forCrc);
    }

    public static function crc16(string $input): string
    {
        $crc = 0xFFFF;
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($input[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public static function receiverMatches(?string $expectedTail, ?string $actualReference): bool
    {
        $expected = preg_replace('/\D+/', '', (string) $expectedTail) ?? '';
        $actual = preg_replace('/\D+/', '', (string) $actualReference) ?? '';
        // A four-digit suffix is too collision-prone for an automatic paid
        // decision. Call this only with an account reference that exposes at
        // least six real digits (for example EasySlip matchedAccount.bankNumber).
        // SlipOK masks receiver references more aggressively and is therefore
        // authorized through its branch-scoped log=true contract instead.
        if (strlen($expected) < 6 || strlen($actual) < 6) return false;
        $length = min(12, strlen($expected), strlen($actual));
        return hash_equals(substr($expected, -$length), substr($actual, -$length));
    }

    /**
     * Apply the provider-specific receiver trust contract.
     *
     * SlipOK validates the receiver against the account registered for the
     * configured API branch when log=true. Its returned account number is
     * always masked and cannot safely be compared locally. EasySlip exposes
     * the full registered matchedAccount.bankNumber, so it must additionally
     * match the owner-configured receiver tail.
     */
    public static function providerReceiverMatches(
        string $provider,
        ?string $expectedTail,
        ?string $matchedAccountReference,
        bool $providerMatched,
        ?string $expectedBranch = null,
        ?string $verifiedBranch = null,
    ): bool {
        if (!$providerMatched) return false;
        if ($provider === 'slipok') {
            $expected = trim((string) $expectedBranch);
            $verified = trim((string) $verifiedBranch);
            return $expected !== '' && $verified !== '' && hash_equals($expected, $verified);
        }
        if ($provider === 'easyslip') {
            return self::receiverMatches($expectedTail, $matchedAccountReference);
        }
        return false;
    }

    private static function tlv(string $tag, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) throw new \InvalidArgumentException('EMV field is too long');
        return $tag . str_pad((string) $length, 2, '0', STR_PAD_LEFT) . $value;
    }
}
