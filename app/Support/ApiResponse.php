<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'errors' => null,
        ], $status);
    }

    public static function paginated(LengthAwarePaginator $paginator, mixed $data = null, array $meta = []): JsonResponse
    {
        return self::success($data ?? $paginator->items(), array_merge($meta, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]));
    }

    public static function error(string $message, int $status = 400, array $errors = [], array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => (object) array_merge($meta, ['message' => $message]),
            'errors' => $errors ?: [['code' => 'error', 'detail' => $message]],
        ], $status);
    }

    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::success($data, $meta, 201);
    }
}
