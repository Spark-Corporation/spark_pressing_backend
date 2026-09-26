<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActor
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $actor = $request->user();

        $map = [
            'staff' => \App\Models\User::class,
            'client' => \App\Models\Client::class,
            'admin' => \App\Models\Admin::class,
        ];

        $expected = $map[$type] ?? null;

        if (! $actor || ! $expected || ! $actor instanceof $expected) {
            return ApiResponse::error('Accès refusé pour ce type de compte.', 403, [
                ['code' => 'forbidden_actor', 'detail' => 'Accès refusé pour ce type de compte.'],
            ]);
        }

        if (property_exists($actor, 'status') && $actor->status === false) {
            return ApiResponse::error('Compte désactivé.', 403, [
                ['code' => 'account_disabled', 'detail' => 'Compte désactivé.'],
            ]);
        }

        return $next($request);
    }
}
