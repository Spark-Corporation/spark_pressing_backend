<?php

namespace App\Support\Money;

class Currency
{
    /** ISO 4217 minor-unit exponents (CDC §8.1 — always store integers). */
    private const DECIMALS = [
        'XAF' => 0,
        'XOF' => 0,
        'GHS' => 2,
        'NGN' => 2,
        'USD' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'MAD' => 2,
        'CDF' => 2,
        'KES' => 2,
        'UGX' => 0,
        'RWF' => 0,
    ];

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValid(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{3}$/', self::normalize($code));
    }

    public static function decimals(string $code): int
    {
        $code = self::normalize($code);

        return self::DECIMALS[$code] ?? 2;
    }

    public static function toMinor(float|int|string $major, string $code): int
    {
        $decimals = self::decimals($code);

        return (int) round(((float) $major) * (10 ** $decimals));
    }

    public static function fromMinor(int $minor, string $code): float
    {
        $decimals = self::decimals($code);

        return $decimals === 0
            ? (float) $minor
            : $minor / (10 ** $decimals);
    }
}
