<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, $meta, $status);
    }

    protected function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return ApiResponse::created($data, $meta);
    }

    protected function page(LengthAwarePaginator $paginator, mixed $data = null, array $meta = []): JsonResponse
    {
        return ApiResponse::paginated($paginator, $data, $meta);
    }
}
