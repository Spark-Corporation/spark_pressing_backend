<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    public static function headerKey(Request $request): ?string
    {
        $key = $request->headers->get('Idempotency-Key')
            ?: $request->headers->get('X-Idempotency-Key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key !== '' && strlen($key) <= 128 ? $key : null;
    }

    public static function requestHash(Request $request): string
    {
        $payload = $request->except(['password', 'password_confirmation', 'current_password']);
        ksort($payload);

        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($payload));
    }

    public static function scope(Request $request): array
    {
        $actor = $request->user();

        return [
            'pressing_id' => $actor->pressing_id ?? null,
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? $actor::class : null,
            'route' => $request->method().' '.$request->path(),
        ];
    }

    /**
     * @return array{record: IdempotencyKey, replay: ?Response}|null
     */
    public static function claim(Request $request, string $key): array
    {
        $hash = self::requestHash($request);
        $scope = self::scope($request);

        return DB::transaction(function () use ($key, $hash, $scope) {
            $existing = IdempotencyKey::query()
                ->where('key', $key)
                ->where('pressing_id', $scope['pressing_id'])
                ->where('actor_type', $scope['actor_type'])
                ->where('actor_id', $scope['actor_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    abort(409, 'Idempotency-Key déjà utilisée avec un autre payload.');
                }

                if ($existing->status === IdempotencyKey::STATUS_COMPLETED && $existing->response_body !== null) {
                    return [
                        'record' => $existing,
                        'replay' => response()->json(
                            $existing->response_body,
                            (int) $existing->response_code
                        )->header('Idempotent-Replay', 'true'),
                    ];
                }

                if ($existing->status === IdempotencyKey::STATUS_PROCESSING
                    && $existing->locked_at
                    && $existing->locked_at->gt(now()->subMinutes(2))) {
                    abort(409, 'Requête idempotente déjà en cours de traitement.');
                }

                $existing->update([
                    'status' => IdempotencyKey::STATUS_PROCESSING,
                    'locked_at' => now(),
                    'response_code' => null,
                    'response_body' => null,
                ]);

                return ['record' => $existing->fresh(), 'replay' => null];
            }

            try {
                $record = IdempotencyKey::query()->create($scope + [
                    'key' => $key,
                    'request_hash' => $hash,
                    'status' => IdempotencyKey::STATUS_PROCESSING,
                    'locked_at' => now(),
                ]);
            } catch (QueryException $e) {
                $record = IdempotencyKey::query()
                    ->where('key', $key)
                    ->where('pressing_id', $scope['pressing_id'])
                    ->where('actor_type', $scope['actor_type'])
                    ->where('actor_id', $scope['actor_id'])
                    ->firstOrFail();

                if ($record->status === IdempotencyKey::STATUS_COMPLETED && $record->response_body !== null) {
                    return [
                        'record' => $record,
                        'replay' => response()->json(
                            $record->response_body,
                            (int) $record->response_code
                        )->header('Idempotent-Replay', 'true'),
                    ];
                }

                abort(409, 'Requête idempotente déjà en cours de traitement.');
            }

            return ['record' => $record, 'replay' => null];
        });
    }

    public static function complete(IdempotencyKey $record, Response $response): void
    {
        $content = $response->getContent();
        $decoded = json_decode($content ?: 'null', true);

        $record->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response_code' => $response->getStatusCode(),
            'response_body' => is_array($decoded) ? $decoded : ['raw' => $content],
            'locked_at' => null,
        ]);
    }

    public static function fail(IdempotencyKey $record): void
    {
        // Libère la clé pour permettre un retry (souvent avec payload corrigé).
        $record->delete();
    }
}
