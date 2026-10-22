<?php

namespace App\Support\Auth;

/** TOTP RFC 6238 (30 s, 6 chiffres, SHA-1) pour l’« Application d’authentification ». */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public static function uri(string $secret, string $account, string $issuer = 'SPARK PRESSING'): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30', rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer));
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepte une dérive d’une période de part et d’autre. */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        foreach ([-30, 0, 30] as $drift) {
            if (hash_equals(self::code($secret, time() + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function recoveryCodes(int $count = 8): array
    {
        return array_map(fn () => strtoupper(bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3))), range(1, $count));
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
