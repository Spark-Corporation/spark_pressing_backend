<?php

namespace App\Http\Controllers\Api\V1\Cash;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Support\Cash\CashPresenter;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Caisse')]
class CashSessionController extends Controller
{
    public function __construct(private readonly CashPresenter $presenter) {}

    /** « Centre de caisse » : KPI, situation, solde théorique/compté, ventilation, flux horaire. */
    public function center(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);

        return $this->ok($this->presenter->center($this->agencyId($request), $this->day($request)));
    }

    /** « Journal de caisse » : encaissements et mouvements (q, date, period, category_id, payment_method, status, flow ; `format=csv`). */
    public function journal(Request $request): JsonResponse|StreamedResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string'],
            'date' => ['nullable', 'date'],
            'period' => ['nullable', 'in:day,week,month'],
            'category_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string'],
            'status' => ['nullable', 'in:synced,validated,pending_approval,to_validate'],
            'flow' => ['nullable', 'in:in,out'],
        ]);
        $agencyId = $this->agencyId($request);

        if ($request->query('format') === 'csv') {
            $rows = $this->presenter->journal($agencyId, $filters, 1, 10000)->items();

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Date / heure', 'Référence', 'Catégorie', 'Opérateur', 'Paiement', 'Flux', 'Montant', 'Statut'], ';');
                foreach ($rows as $r) {
                    fputcsv($out, [$r['at'], $r['reference'], $r['category'], $r['operator'], $r['payment_method']['label'], $r['flow']['label'], $r['amount'], $r['status']['label']], ';');
                }
                fclose($out);
            }, 'journal-caisse-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $page = $this->presenter->journal($agencyId, $filters, max(1, (int) $request->get('page', 1)), min(100, (int) $request->get('per_page', 20)));

        return $this->page($page, $page->items(), [
            'totals' => $this->presenter->center($agencyId, $this->day($request))['journal_totals'],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $day = $this->day($request);

        return $this->ok([
            'session' => $this->presenter->sessionPayload($this->presenter->session($agencyId, $day)),
            'available_cash' => $this->presenter->availableCash($agencyId, $day),
            'double_control_threshold' => (int) ($request->user()->pressing?->cash_double_control_threshold ?? 250000),
        ]);
    }

    /** Ouverture de caisse (solde d’ouverture). */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_balance' => ['required', 'integer', 'min:0']]);
        $agencyId = $this->agencyId($request);
        $agency = Agency::query()->findOrFail($agencyId);
        $day = CarbonImmutable::now();

        if ($this->presenter->session($agencyId, $day)) {
            throw ValidationException::withMessages(['session' => 'La caisse est déjà ouverte pour aujourd’hui.']);
        }

        $session = CashSession::query()->create([
            'pressing_id' => $agency->pressing_id,
            'agency_id' => $agency->id,
            'code' => sprintf('CAI-%s-%s', strtoupper((string) $agency->code_prefix ?: 'AG'), $day->format('md')),
            'business_date' => $day->toDateString(),
            'opened_at' => now(),
            'opened_by' => $request->user()->id,
            'opening_balance' => $data['opening_balance'],
            'status' => 'open',
        ]);

        AuditLog::record('cash.session_opened', $session, $data);

        return $this->created($this->presenter->sessionPayload($session->load('opener:id,fullname')));
    }

    /** Écran « Clôture et rapprochement journalier ». */
    public function closing(CashSession $cashSession): JsonResponse
    {
        return $this->ok($this->presenter->closing($cashSession->load(['opener:id,fullname', 'counter:id,fullname', 'controller:id,fullname'])));
    }

    /** Comptage réel par moyen de paiement (+ justification si écart). */
    public function count(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->assertOpen($cashSession);
        $data = $request->validate([
            'counted' => ['required', 'array'],
            'counted.cash' => ['required', 'integer', 'min:0'],
            'counted.mobile_money' => ['nullable', 'integer', 'min:0'],
            'counted.card' => ['nullable', 'integer', 'min:0'],
            'difference_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $cashSession->update([
            'counted' => array_map('intval', array_filter($data['counted'], fn ($v) => $v !== null)),
            'counted_at' => now(),
            'counted_by' => $request->user()->id,
            'difference_reason' => $data['difference_reason'] ?? $cashSession->difference_reason,
            'controlled_at' => null,
            'controlled_by' => null,
        ]);
        $closing = $this->presenter->closing($cashSession->fresh());
        $cashSession->update(['difference' => $closing['result']['difference']]);
        AuditLog::record('cash.session_counted', $cashSession, $data);

        return $this->ok($this->presenter->closing($cashSession->fresh()));
    }

    /** Double contrôle : validation du comptage par une seconde personne habilitée. */
    public function control(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->assertOpen($cashSession);
        abort_unless($request->user()->can('cash.approve'), 403, 'Validation réservée à un manager habilité.');
        if (! $cashSession->counted_by) {
            throw ValidationException::withMessages(['session' => 'Le comptage doit être saisi avant le contrôle.']);
        }
        if ((int) $cashSession->counted_by === (int) $request->user()->id) {
            throw ValidationException::withMessages(['session' => 'Le contrôle doit être effectué par une autre personne que le compteur.']);
        }

        $cashSession->update(['controlled_at' => now(), 'controlled_by' => $request->user()->id]);
        AuditLog::record('cash.session_controlled', $cashSession);

        return $this->ok($this->presenter->closing($cashSession->fresh()));
    }

    /** « Clôturer » : verrouille le journal de la journée. */
    public function close(Request $request, CashSession $cashSession): JsonResponse
    {
        $this->assertOpen($cashSession);
        $closing = $this->presenter->closing($cashSession);
        $blocking = collect($closing['checklist']['items'])->where('done', false)->pluck('label');
        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages(['session' => 'Conditions de clôture non remplies : '.$blocking->implode(', ').'.']);
        }

        $cashSession->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id]);
        AuditLog::record('cash.session_closed', $cashSession, ['difference' => $cashSession->difference]);

        return $this->ok($this->presenter->closing($cashSession->fresh()));
    }

    /** « Rapport de clôture » (PDF). */
    public function report(CashSession $cashSession): Response
    {
        $closing = $this->presenter->closing($cashSession);
        $html = view('documents.cash-closing', ['closing' => $closing, 'agency' => Agency::query()->find($cashSession->agency_id)])->render();

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->download($closing['report']['filename']);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function assertOpen(CashSession $session): void
    {
        if (! $session->isOpen()) {
            throw ValidationException::withMessages(['session' => 'Cette caisse est clôturée.']);
        }
    }

    private function agencyId(Request $request): int
    {
        $id = app(TenantContext::class)->agencyId ?? $request->user()->agency_id;
        abort_unless($id, 422, 'Agence d’opération requise (X-Agency-Id).');

        return (int) $id;
    }

    private function day(Request $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->query('date', now()->toDateString()));
    }
}
