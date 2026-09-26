<?php

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Http\Controllers\Controller;
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
        return $this->ok(array_merge([
            'currency' => config('spark.currency'),
            'scope' => [
                'pressing_id' => $request->user()->pressing_id,
                'agency_id' => app(TenantContext::class)->agencyId,
            ],
        ], Metrics::dashboardToday()));
    }
}
