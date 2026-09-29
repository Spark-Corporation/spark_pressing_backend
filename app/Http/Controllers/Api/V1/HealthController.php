<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Throwable;

#[Group('Système')]
#[Unauthenticated]
class HealthController extends Controller
{
    #[Endpoint('Santé de l\'API', 'Vérifie que l\'API et la base sont joignables (sonde LB / ENF02).')]
    public function __invoke(): JsonResponse
    {
        $dbOk = false;
        $dbError = null;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $dbOk = true;
        } catch (Throwable $e) {
            $dbError = app()->environment('production') ? 'unavailable' : $e->getMessage();
        }

        $payload = [
            'name' => 'Spark Pressing API',
            'version' => 'v1',
            'status' => $dbOk ? 'ok' : 'degraded',
            'database' => $dbOk ? 'ok' : 'error',
            'currency' => config('spark.currency'),
            'locale' => config('app.locale'),
        ];

        if ($dbError) {
            $payload['database_error'] = $dbError;
        }

        return ApiResponse::success($payload, [], $dbOk ? 200 : 503);
    }
}
