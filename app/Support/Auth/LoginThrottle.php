<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\RateLimiter;

/**
 * « Après 5 échecs, accès limité pendant 15 minutes » (écran Authentification).
 */
class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public static function key(string $actor, string $identifier): string
    {
        return 'login-fail:'.$actor.':'.sha1(mb_strtolower(trim($identifier)));
    }

    public static function locked(string $key, int $max = self::MAX_ATTEMPTS): bool
    {
        return RateLimiter::tooManyAttempts($key, $max);
    }

    public static function retryAfter(string $key): int
    {
        return RateLimiter::availableIn($key);
    }

    public static function fail(string $key, int $max = self::MAX_ATTEMPTS, int $lockSeconds = self::LOCK_SECONDS): int
    {
        RateLimiter::hit($key, $lockSeconds);

        return max(0, $max - RateLimiter::attempts($key));
    }

    public static function clear(string $key): void
    {
        RateLimiter::clear($key);
    }
}
