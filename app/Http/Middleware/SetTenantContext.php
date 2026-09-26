<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class);
        $actor = $request->user();

        if ($actor instanceof Admin) {
            return $this->forSuperadmin($request, $tenant, $next);
        }

        if ($actor instanceof User) {
            return $this->forStaff($request, $tenant, $actor, $next);
        }

        if ($actor instanceof Client) {
            $tenant->set((int) $actor->pressing_id, null, bypass: false, actor: 'client');

            return $next($request);
        }

        $tenant->clear();

        return $next($request);
    }

    private function forSuperadmin(Request $request, TenantContext $tenant, Closure $next): Response
    {
        $pressingId = $this->headerOrQuery($request, 'X-Pressing-Id', 'pressing_id');
        $agencyId = $this->headerOrQuery($request, 'X-Agency-Id', 'agency_id');

        if ($agencyId && $pressingId) {
            $exists = Agency::query()
                ->where('id', $agencyId)
                ->where('pressing_id', $pressingId)
                ->exists();

            if (! $exists) {
                return ApiResponse::error('Cette agence n\'appartient pas au pressing indiqué.', 403, [
                    ['code' => 'tenant_mismatch', 'detail' => 'Agence hors pressing.'],
                ]);
            }
        }

        $tenant->set(
            $pressingId ? (int) $pressingId : null,
            $agencyId ? (int) $agencyId : null,
            bypass: $pressingId === null,
            actor: 'superadmin'
        );

        return $next($request);
    }

    private function forStaff(Request $request, TenantContext $tenant, User $user, Closure $next): Response
    {
        if (! $user->pressing_id) {
            return ApiResponse::error('Compte staff sans pressing.', 403);
        }

        $requestedAgency = $this->headerOrQuery($request, 'X-Agency-Id', 'agency_id');

        if ($user->canViewAllAgencies()) {
            $agencyId = null;

            if ($requestedAgency) {
                if (! $user->canAccessAgency((int) $requestedAgency)) {
                    return ApiResponse::error('Agence hors de votre pressing.', 403, [
                        ['code' => 'tenant_mismatch', 'detail' => 'Agence non autorisée.'],
                    ]);
                }
                $agencyId = (int) $requestedAgency;
            }

            $tenant->set((int) $user->pressing_id, $agencyId, bypass: false, actor: 'staff');

            return $next($request);
        }

        $agencyId = (int) $user->agency_id;

        if ($requestedAgency && (int) $requestedAgency !== $agencyId && ! $user->canAccessAgency((int) $requestedAgency)) {
            return ApiResponse::error('Vous ne pouvez pas changer d\'agence.', 403, [
                ['code' => 'agency_locked', 'detail' => 'Périmètre limité à votre agence.'],
            ]);
        }

        if ($requestedAgency && $user->canAccessAgency((int) $requestedAgency)) {
            $agencyId = (int) $requestedAgency;
        }

        $tenant->set((int) $user->pressing_id, $agencyId, bypass: false, actor: 'staff');

        return $next($request);
    }

    private function headerOrQuery(Request $request, string $header, string $query): ?string
    {
        $value = $request->header($header) ?: $request->query($query);

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
