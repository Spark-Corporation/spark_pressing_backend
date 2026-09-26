<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Unauthenticated;

#[Group('Système')]
#[Unauthenticated]
class HealthController extends Controller
{
    #[Endpoint('Santé de l\'API', 'Vérifie que l\'API est joignable.')]
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success([
            'name' => 'Spark Pressing API',
            'version' => 'v1',
            'currency' => config('spark.currency'),
        ]);
    }
}
