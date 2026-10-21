<?php

namespace App\Http\Controllers\Api\V1\Tenancy;

use App\Actions\License\RenewLicense;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\LicensePayment;
use App\Models\Pressing;
use App\Support\Licensing\LicenseOverview;
use App\Support\Licensing\LicensePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Superadmin')]
class LicenseController extends Controller
{
    public function __construct(private readonly LicensePresenter $presenter) {}

    /** KPI, échéances à traiter et prévision de revenu du centre des licences. */
    #[QueryParam('period', 'string', 'Fenêtre des KPI renouvellements / revenu : day, week ou month.', required: false, example: 'month')]
    public function overview(Request $request, LicenseOverview $overview): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(['day', 'week', 'month'])]]);

        return $this->ok($overview->build($data['period'] ?? 'month'));
    }

    #[QueryParam('q', 'string', 'Recherche pressing, référence ou plan.', required: false)]
    #[QueryParam('plan', 'string', 'Code plan (growth, business, enterprise).', required: false)]
    #[QueryParam('country', 'string', 'Code pays ISO2 d’une agence du pressing.', required: false, example: 'CI')]
    #[QueryParam('pressing_id', 'integer', required: false)]
    #[QueryParam('status', 'string', 'active, expiring, expired, suspended, inactive.', required: false)]
    #[QueryParam('expires_within', 'integer', 'Échéance dans les N prochains jours.', required: false, example: 30)]
    #[QueryParam('per_page', 'integer', required: false, example: 20)]
    public function index(Request $request): JsonResponse
    {
        $items = $this->filtered($request)
            ->with(['pressing:id,name', 'latestPayment', 'currentPayment'])
            ->latest()
            ->paginate(min(100, max(1, (int) $request->input('per_page', 50))));

        return $this->page($items, $this->presenter->rows($items->items()));
    }

    /** Export CSV de la liste filtrée (mêmes filtres que l’index). */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->presenter->rows(
            $this->filtered($request)->with(['pressing:id,name', 'latestPayment', 'currentPayment'])->latest()->get()
        );

        return $this->csv('licences-'.now()->format('Ymd').'.csv', [
            'Référence', 'Pressing', 'Pays', 'Plan', 'Périodicité', 'Activation', 'Expiration',
            'Reste (j)', 'Agences', 'Utilisateurs', 'Auto', 'Statut', 'Paiement',
        ], array_map(fn (array $r) => [
            $r['reference'], $r['pressing']['name'], $r['pressing']['country'], $r['plan']['label'],
            $r['periodicity']['label'], $r['activated_at'], $r['expires_at'], $r['days_left'],
            $r['usage']['agencies']['used'].'/'.$r['usage']['agencies']['limit'],
            $r['usage']['users']['used'].'/'.$r['usage']['users']['limit'],
            $r['auto_renew'] ? 'Activé' : 'Désactivé', $r['status']['label'], $r['payment_status']['label'],
        ], $rows));
    }

    /** Page « Détail et renouvellement ». */
    public function show(License $license): JsonResponse
    {
        return $this->ok($this->presenter->detail($license));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pressing_id' => ['required', 'exists:pressings,id'],
            'plan' => ['nullable', 'string', 'max:32'],
            'periodicity' => ['nullable', Rule::in(array_keys(config('licensing.periodicities')))],
            'auto_renew' => ['sometimes', 'boolean'],
            'seats' => ['nullable', 'integer', 'min:1'],
            'max_agencies' => ['nullable', 'integer', 'min:1'],
            'storage_gb' => ['nullable', 'integer', 'min:1'],
            'months' => ['nullable', 'integer', 'min:1'],
            'activate' => ['sometimes', 'boolean'],
        ]);

        $plan = $data['plan'] ?? 'standard';
        $periodicity = $data['periodicity'] ?? 'annual';

        $license = License::query()->create([
            'pressing_id' => $data['pressing_id'],
            'plan' => $plan,
            'periodicity' => $periodicity,
            'auto_renew' => $data['auto_renew'] ?? false,
            'seats' => $data['seats'] ?? config("licensing.plans.{$plan}.limits.users", 5),
            'max_agencies' => $data['max_agencies'] ?? null,
            'storage_gb' => $data['storage_gb'] ?? null,
            'code' => strtoupper(Str::random(12)),
            'status' => true,
        ]);

        AuditLog::record('license.created', $license, ['plan' => $plan]);

        if ($request->boolean('activate')) {
            $months = (int) ($data['months'] ?? config("licensing.periodicities.{$periodicity}.months", 12));
            $this->activateLicense($license, $months);
        }

        return $this->created($this->presenter->row($license->fresh()));
    }

    /** Modification / changement de plan. */
    public function update(Request $request, License $license): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', 'max:32'],
            'periodicity' => ['sometimes', Rule::in(array_keys(config('licensing.periodicities')))],
            'auto_renew' => ['sometimes', 'boolean'],
            'seats' => ['sometimes', 'integer', 'min:1'],
            'max_agencies' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'storage_gb' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $planChanged = isset($data['plan']) && $data['plan'] !== $license->plan;
        if ($planChanged && ! isset($data['seats']) && ($seats = config("licensing.plans.{$data['plan']}.limits.users"))) {
            $data['seats'] = $seats;
        }

        $license->update($data);

        AuditLog::record($planChanged ? 'license.plan_changed' : 'license.updated', $license, $data);

        return $this->ok($this->presenter->row($license->fresh()));
    }

    public function activate(Request $request, License $license): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->activateLicense($license, (int) ($data['months'] ?? 12));

        return $this->ok($this->presenter->row($license->fresh()));
    }

    /** « Valider et renouveler » — multipart si justificatif joint. */
    public function renew(Request $request, License $license, RenewLicense $action): JsonResponse
    {
        $data = $request->validate([
            'periodicity' => ['required', Rule::in(array_keys(config('licensing.periodicities')))],
            'plan' => ['nullable', Rule::in(array_keys(config('licensing.plans')))],
            'payment_method' => ['required', Rule::in(array_keys(config('licensing.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:64'],
            'effective_at' => ['nullable', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
            'mark_paid' => ['sometimes', 'boolean'],
            'proof' => ['nullable', 'file', 'mimes:'.implode(',', config('licensing.proof.mimes')), 'max:'.config('licensing.proof.max_kb')],
        ]);

        $action->handle($request->user(), $license, $data, $request->file('proof'));

        return $this->created($this->presenter->detail($license->fresh()));
    }

    public function suspend(Request $request, License $license): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $license->update([
            'status' => false,
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'] ?? null,
        ]);

        AuditLog::record('license.suspended', $license, $data);

        return $this->ok($this->presenter->row($license->fresh()));
    }

    public function reactivate(License $license): JsonResponse
    {
        $license->update(['status' => true, 'suspended_at' => null, 'suspension_reason' => null]);

        AuditLog::record('license.reactivated', $license);

        return $this->ok($this->presenter->row($license->fresh()));
    }

    /** Historique des renouvellements et paiements (`format=csv` pour « Exporter »). */
    #[QueryParam('format', 'string', 'json (défaut) ou csv.', required: false)]
    public function payments(Request $request, License $license): JsonResponse|StreamedResponse
    {
        if ($request->query('format') === 'csv') {
            $rows = $license->payments()->latest()->get()->map(fn (LicensePayment $p) => $this->presenter->payment($p));

            return $this->csv("paiements-{$license->reference}.csv", [
                'Date', 'Période', 'Montant', 'Devise', 'Moyen', 'Référence', 'Facture', 'Statut',
            ], $rows->map(fn (array $p) => [
                $p['date'], $p['period']['label'], $p['total'], $p['currency'], $p['payment_method']['label'],
                $p['reference'], $p['invoice_number'], $p['status']['label'],
            ])->all());
        }

        $items = $license->payments()->latest()->paginate(20);

        return $this->page($items, collect($items->items())->map(fn (LicensePayment $p) => $this->presenter->payment($p))->all());
    }

    public function validatePayment(License $license, LicensePayment $payment, RenewLicense $action): JsonResponse
    {
        abort_unless($payment->license_id === $license->id, 404);

        return $this->ok($this->presenter->payment($action->validatePayment($payment)));
    }

    /** Facture PDF d’un paiement (« Télécharger facture »). */
    public function invoice(License $license, LicensePayment $payment): Response
    {
        abort_unless($payment->license_id === $license->id, 404);

        $html = view('documents.license-invoice', [
            'license' => $license->loadMissing('pressing'),
            'payment' => $this->presenter->payment($payment),
        ])->render();
        $filename = $payment->invoice_number.'.pdf';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->download($filename);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function destroy(License $license): JsonResponse
    {
        $license->delete();

        return $this->ok(['deleted' => true]);
    }

    public function current(Request $request): JsonResponse
    {
        $pressingId = $request->user()?->pressing_id;
        abort_unless($pressingId, 404);

        $license = License::query()
            ->where('pressing_id', $pressingId)
            ->latest()
            ->first();

        return $this->ok($license ? [
            'id' => $license->id,
            'plan' => $license->plan,
            'seats' => $license->seats,
            'is_activated' => $license->is_activated,
            'is_valid' => $license->isValid(),
            'expires_at' => $license->expires_at?->toIso8601String(),
            'pressing' => Pressing::query()->find($pressingId)?->name,
        ] : null);
    }

    private function filtered(Request $request): Builder
    {
        $request->validate([
            'status' => ['nullable', Rule::in(License::STATUSES)],
            'expires_within' => ['nullable', 'integer', 'min:0'],
        ]);

        return License::query()
            ->when($request->pressing_id, fn ($q, $id) => $q->where('pressing_id', $id))
            ->when($request->plan, fn ($q, $plan) => $q->where('plan', $plan))
            ->when($request->status, fn ($q, $status) => $q->withStatus($status))
            ->when($request->filled('expires_within'), fn ($q) => $q
                ->whereBetween('expires_at', [now(), now()->addDays((int) $request->expires_within)]))
            ->when($request->country, fn ($q, $country) => $q
                ->whereHas('pressing.agencies', fn ($a) => $a->where('country_code', strtoupper($country))))
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$term}%")
                ->orWhere('plan', 'like', "%{$term}%")
                ->orWhereHas('pressing', fn ($p) => $p->where('name', 'like', "%{$term}%"))));
    }

    private function csv(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function activateLicense(License $license, int $months): void
    {
        $license->update([
            'is_activated' => true,
            'activated_at' => now(),
            'period_started_at' => now(),
            'expires_at' => now()->addMonths($months),
            'status' => true,
        ]);

        AuditLog::record('license.activated', $license, ['months' => $months]);
    }
}
