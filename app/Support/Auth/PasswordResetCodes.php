<?php

namespace App\Support\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Sms\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Réinitialisation en 3 étapes : code à 6 chiffres (10 min) → jeton de réinitialisation → nouveau mot de passe.
 * Anti-abus : 2 demandes par 15 minutes et par compte, 5 essais par code, tentatives journalisées.
 */
class PasswordResetCodes
{
    public const CODE_TTL = 600;

    public const TOKEN_TTL = 900;

    public const MAX_REQUESTS = 2;

    public const REQUEST_WINDOW = 900;

    public const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    /** @return list<array{code:string,label:string,destination:string}> */
    public function channels(string $email): array
    {
        $user = $this->user($email);
        $channels = [['code' => 'email', 'label' => 'E-mail', 'destination' => self::maskEmail($email)]];

        if ($user?->phone_number) {
            $channels[] = ['code' => 'sms', 'label' => 'SMS', 'destination' => SmsSender::mask($user->phone_number)];
        }

        return $channels;
    }

    /** @return int|null secondes avant nouvelle demande si la limite est atteinte */
    public function throttled(string $email): ?int
    {
        $key = $this->requestKey($email);

        return RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS) ? RateLimiter::availableIn($key) : null;
    }

    public function send(string $email, string $channel): void
    {
        RateLimiter::hit($this->requestKey($email), self::REQUEST_WINDOW);

        $user = $this->user($email);
        if (! $user) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->codeKey($user), ['hash' => Hash::make($code), 'attempts' => 0], self::CODE_TTL);

        $message = "SPARK PRESSING : votre code de réinitialisation est {$code}. Il expire dans 10 minutes.";
        if ($channel === 'sms' && $user->phone_number) {
            $this->sms->send($user->phone_number, $message);
        } else {
            Mail::raw($message, fn ($m) => $m->to($user->email)->subject('Code de réinitialisation SPARK PRESSING'));
        }

        $this->audit($user, 'auth.reset_code_sent', ['channel' => $channel]);
    }

    /** @return string|null jeton de réinitialisation si le code est valide */
    public function verify(string $email, string $code): ?string
    {
        $user = $this->user($email);
        $entry = $user ? Cache::get($this->codeKey($user)) : null;

        if (! $entry || $entry['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            return null;
        }

        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($this->codeKey($user), $entry, self::CODE_TTL);
            $this->audit($user, 'auth.reset_code_failed', ['attempts' => $entry['attempts']]);

            return null;
        }

        Cache::forget($this->codeKey($user));
        $token = Str::random(64);
        Cache::put($this->tokenKey($user), Hash::make($token), self::TOKEN_TTL);
        $this->audit($user, 'auth.reset_code_verified');

        return $token;
    }

    public function consume(User $user, string $token): bool
    {
        $hash = Cache::get($this->tokenKey($user));
        if (! $hash || ! Hash::check($token, $hash)) {
            return false;
        }

        Cache::forget($this->tokenKey($user));

        return true;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }

    private function user(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    private function requestKey(string $email): string
    {
        return 'pwd-reset-req:'.sha1(mb_strtolower($email));
    }

    private function codeKey(User $user): string
    {
        return 'pwd-reset-code:'.$user->id;
    }

    private function tokenKey(User $user): string
    {
        return 'pwd-reset-token:'.$user->id;
    }

    private function audit(User $user, string $action, array $payload = []): void
    {
        AuditLog::query()->create([
            'pressing_id' => $user->pressing_id,
            'agency_id' => $user->agency_id,
            'actor_type' => User::class,
            'actor_id' => $user->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'payload' => $payload,
            'ip_address' => request()?->ip(),
        ]);
    }
}
