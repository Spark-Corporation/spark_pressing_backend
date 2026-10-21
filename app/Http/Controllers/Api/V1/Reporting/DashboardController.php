<?php

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Support\Reporting\Metrics;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('POS')]
class DashboardController extends Controller
{
    public function agency(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = app(TenantContext::class);
        $previousAgency = $tenant->agencyId;
        $networkWide = $user->canViewAllAgencies();

        if ($networkWide) {
            $tenant->agencyId = null;
            $agencies = Agency::query()
                ->where('pressing_id', $user->pressing_id)
                ->where('status', true)
                ->orderBy('name')
                ->get(['id', 'name', 'address', 'currency', 'code_prefix', 'status', 'pressing_id']);
        } else {
            $agencies = Agency::query()
                ->whereKey($tenant->agencyId ?? $user->agency_id)
                ->get(['id', 'name', 'address', 'currency', 'code_prefix', 'status', 'pressing_id']);
        }

        $payload = Metrics::dashboardNetwork($agencies, $user->pressing_id);
        $tenant->agencyId = $previousAgency;

        return $this->ok(array_merge($payload, [
            'scope' => [
                'pressing_id' => $user->pressing_id,
                'agency_id' => $previousAgency ?? $user->agency_id,
                'network_wide' => $networkWide,
            ],
        ]));
    }
}
