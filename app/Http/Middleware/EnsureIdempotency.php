<?php

namespace App\Http\Middleware;

use App\Support\Idempotency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = Idempotency::headerKey($request);
        if (! $key) {
            return $next($request);
        }

        $claim = Idempotency::claim($request, $key);
        if ($claim['replay'] instanceof Response) {
            return $claim['replay'];
        }

        $record = $claim['record'];

        try {
            /** @var Response $response */
            $response = $next($request);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                Idempotency::complete($record, $response);
                $response->headers->set('Idempotent-Replay', 'false');
            } else {
                // Requête mal formée / métier refusé → libérer la clé pour retry (rollback logique).
                Idempotency::fail($record);
            }

            return $response;
        } catch (Throwable $e) {
            Idempotency::fail($record);
            throw $e;
        }
    }
}
