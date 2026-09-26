<?php

namespace App\Http\Controllers\Api\V1\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PrintLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Organisation')]
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('pressing_id', $request->user()->pressing_id)
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->action))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->paginate(50);

        return $this->page($logs, $logs->items());
    }

    public function prints(Request $request): JsonResponse
    {
        $logs = PrintLog::query()->latest()->paginate(50);

        return $this->page($logs, $logs->items());
    }
}
