<?php

namespace App\Http\Controllers\Api\V1\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Support\Reporting\NetworkPresenter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Rapports')]
class NetworkController extends Controller
{
    public function __construct(private readonly NetworkPresenter $presenter) {}

    /**
     * « Vue consolidée multi-agences » : KPIs réseau, CA journalier, classement, comparaison,
     * alertes opérationnelles et état des agences (`date_from`/`date_to`, mois en cours par défaut).
     */
    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->canViewAllAgencies(), 403);
        [$from, $to] = $this->range($request);

        return $this->ok($this->networkScope(fn () => $this->presenter->overview($request->user()->pressing_id, $from, $to)));
    }

    /** « Détail agence » : KPIs, comparaison au réseau, atelier, clients, retards & impayés, équipe, historique, synchro. */
    public function agency(Request $request, Agency $agency): JsonResponse
    {
        abort_unless((int) $agency->pressing_id === (int) $request->user()->pressing_id && $request->user()->canAccessAgency($agency->id), 403);
        [$from, $to] = $this->range($request);

        return $this->ok($this->networkScope(fn () => $this->presenter->agency($agency, $from, $to)));
    }

    private function range(Request $request): array
    {
        $data = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);

        return [
            isset($data['date_from']) ? CarbonImmutable::parse($data['date_from'])->startOfDay() : CarbonImmutable::now()->startOfMonth(),
            isset($data['date_to']) ? CarbonImmutable::parse($data['date_to'])->endOfDay() : CarbonImmutable::now()->endOfDay(),
        ];
    }

    private function networkScope(callable $callback): array
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->agencyId;
        $tenant->agencyId = null;
        try {
            return $callback();
        } finally {
            $tenant->agencyId = $previous;
        }
    }
}
