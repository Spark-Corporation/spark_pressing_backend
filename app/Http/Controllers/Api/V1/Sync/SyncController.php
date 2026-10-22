<?php

namespace App\Http\Controllers\Api\V1\Sync;

use App\Http\Controllers\Controller;
use App\Models\SyncBatch;
use App\Support\Sync\SyncOperationRunner;
use App\Support\Sync\SyncPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Synchronisation')]
class SyncController extends Controller
{
    public function __construct(private readonly SyncPresenter $presenter) {}

    /** Centre de synchronisation (Figma 12) : KPIs réseau, lots, appareils, alertes. */
    public function center(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);
        $agencyId = isset($data['agency_id']) ? (int) $data['agency_id'] : null;
        if ($agencyId && ! $request->user()->canAccessAgency($agencyId)) {
            abort(403);
        }

        return $this->ok($this->presenter->center((int) $request->user()->pressing_id, $agencyId, (int) ($data['days'] ?? 30)));
    }

    public function showBatch(Request $request, SyncBatch $batch): JsonResponse
    {
        abort_unless((int) $batch->pressing_id === (int) $request->user()->pressing_id, 404);

        return $this->ok($this->presenter->batch($batch->load(['agency:id,name', 'user:id,fullname'])));
    }

    /** « Résoudre le conflit » puis « Appliquer et reprendre » (`decision=keep_discount|remove_discount|hold`). */
    public function resolve(Request $request, SyncBatch $batch, string $clientUuid, SyncOperationRunner $runner): JsonResponse
    {
        abort_unless((int) $batch->pressing_id === (int) $request->user()->pressing_id, 404);
        $data = $request->validate(['decision' => ['required', 'in:keep_discount,remove_discount,hold']]);
        $result = $runner->resolve($request->user(), $batch, $clientUuid, $data['decision']);

        return $this->ok([
            'operation' => collect($result)->except('payload')->all(),
            'batch' => $this->presenter->batch($batch->fresh()->load(['agency:id,name', 'user:id,fullname'])),
        ]);
    }
}
