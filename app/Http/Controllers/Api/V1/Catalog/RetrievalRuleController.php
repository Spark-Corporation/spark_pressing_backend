<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\RetrievalRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

/**
 * Écrans Figma « 17.02 — Heures de retrait » : délai de disponibilité du linge par agence et prestation.
 * Calcul en heures écoulées ; s’applique aux futurs dépôts (les dates déjà prévues sont conservées).
 */
#[Group('Catalogue')]
class RetrievalRuleController extends Controller
{
    public const NOTICE = 'Ces règles calculent l’heure de retrait prévue. Elles ne configurent ni les tournées ni les créneaux de collecte/livraison. Les changements concernent les futurs dépôts.';

    /** « Règles de disponibilité » (`q`, `agency_id`, `service`, `received_at` pour l’exemple de calcul). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'agency_id' => ['nullable', 'integer'],
            'service' => ['nullable', Rule::in(array_keys(RetrievalRule::SERVICES))],
            'received_at' => ['nullable', 'date'],
        ]);
        $reference = $this->reference($data['received_at'] ?? null);
        $rules = RetrievalRule::query()->with('agency:id,name')->latest('id')->get();

        $rows = $rules->map(fn (RetrievalRule $r) => $this->row($r, $reference))
            ->when($data['agency_id'] ?? null, fn ($c, $id) => $c->filter(fn ($r) => $r['agency_id'] === (int) $id || $r['agency_id'] === null))
            ->when($data['service'] ?? null, fn ($c, $s) => $c->where('service', $s))
            ->when($data['q'] ?? null, fn ($c, $q) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['agency'].' '.$r['service_label']), mb_strtolower($q))))
            ->sortBy(fn ($r) => [$r['agency'], array_search($r['service'], array_keys(RetrievalRule::SERVICES), true)])
            ->values();

        return $this->ok($rows->all(), [
            'active_rules' => $rules->where('status', true)->count(),
            'agencies' => $rules->pluck('agency_id')->filter()->unique()->count(),
            'services' => collect(RetrievalRule::SERVICES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
            'reference_received_at' => $reference->toIso8601String(),
            'results_label' => $rows->count().' résultat'.($rows->count() > 1 ? 's' : ''),
            'notice' => self::NOTICE,
        ]);
    }

    /** « Aperçu du délai » et vérifications (`agency_id`, `service`, `hours`, `received_at`, `ignore_id`). */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateRule($request, true);

        return $this->ok($this->checks($request, $data));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);
        $this->assertValid($request, $data);
        $rule = RetrievalRule::query()->create([
            'pressing_id' => $request->user()->pressing_id,
            'agency_id' => $data['agency_id'] ?? null,
            'service' => $data['service'],
            'hours' => $data['hours'],
            'status' => $data['status'] ?? true,
            'updated_by' => $request->user()->id,
        ]);
        AuditLog::record('retrieval_rule.created', $rule, ['service' => $rule->service, 'hours' => $rule->hours]);

        return $this->created($this->row($rule->load('agency:id,name'), $this->reference(null)), ['notice' => self::NOTICE]);
    }

    public function update(Request $request, RetrievalRule $retrievalRule): JsonResponse
    {
        $data = $this->validateRule($request) + ['ignore_id' => $retrievalRule->id];
        $this->assertValid($request, $data);
        $before = $retrievalRule->hours;
        $retrievalRule->update(collect($data)->only(['agency_id', 'service', 'hours', 'status'])->all() + ['updated_by' => $request->user()->id]);
        AuditLog::record('retrieval_rule.updated', $retrievalRule, ['from' => $before, 'to' => $retrievalRule->hours]);

        return $this->ok($this->row($retrievalRule->load('agency:id,name'), $this->reference(null)), ['notice' => self::NOTICE]);
    }

    /** « Supprimer cette règle ? » — les dates de retrait déjà prévues sont conservées. */
    public function destroy(RetrievalRule $retrievalRule): JsonResponse
    {
        $label = $this->label($retrievalRule->load('agency:id,name'));
        $retrievalRule->delete();
        AuditLog::record('retrieval_rule.deleted', null, ['rule' => $label]);

        return $this->ok([
            'deleted' => true,
            'message' => "{$label}. Cette règle ne sera plus proposée pour les futurs dépôts. Les dates de retrait déjà prévues sur les dépôts existants sont conservées.",
        ]);
    }

    private function row(RetrievalRule $r, CarbonImmutable $reference): array
    {
        return [
            'id' => $r->id,
            'agency_id' => $r->agency_id,
            'agency' => $r->agency?->name ?? 'Toutes les agences',
            'service' => $r->service,
            'service_label' => RetrievalRule::SERVICES[$r->service] ?? $r->service,
            'hours' => $r->hours,
            'hours_label' => "{$r->hours} h",
            'example' => $this->example($reference, $r->hours),
            'status' => $r->status ? 'active' : 'inactive',
            'status_label' => $r->status ? 'Actif' : 'Inactif',
            'label' => $this->label($r),
        ];
    }

    private function example(CarbonImmutable $receivedAt, int $hours): array
    {
        $ready = $receivedAt->addHours($hours);

        return [
            'received_at' => $receivedAt->toIso8601String(),
            'ready_at' => $ready->toIso8601String(),
            'label' => $receivedAt->locale('fr')->isoFormat('D MMM HH:mm').' → '.$ready->locale('fr')->isoFormat('D MMM HH:mm'),
            'received_label' => 'Réception · '.$receivedAt->locale('fr')->isoFormat('ddd D MMM [à] HH:mm'),
            'ready_label' => 'Retrait prévu · '.$ready->locale('fr')->isoFormat('ddd D MMM [à] HH:mm'),
            'method' => 'elapsed_hours',
        ];
    }

    private function checks(Request $request, array $data): array
    {
        $duplicate = RetrievalRule::query()->where('service', $data['service'] ?? null)->where('status', true)
            ->when($data['agency_id'] ?? null, fn ($q, $id) => $q->where('agency_id', $id), fn ($q) => $q->whereNull('agency_id'))
            ->when($data['ignore_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        $hours = (int) ($data['hours'] ?? 0);

        return [
            'example' => $hours ? $this->example($this->reference($data['received_at'] ?? null), $hours) : null,
            'checks' => [
                ['code' => 'hours', 'ok' => $hours >= 1 && $hours <= 720, 'label' => 'Délai entre 1 h et 720 h'],
                ['code' => 'unique', 'ok' => ! $duplicate, 'label' => $duplicate ? 'Une règle active existe déjà pour cette agence et cette prestation' : 'Aucune règle en double'],
                ['code' => 'future_only', 'ok' => true, 'label' => 'Les dépôts existants gardent leur date de retrait'],
            ],
            'valid' => $hours >= 1 && $hours <= 720 && ! $duplicate,
        ];
    }

    private function assertValid(Request $request, array $data): void
    {
        $checks = $this->checks($request, $data);
        if (! $checks['valid']) {
            throw ValidationException::withMessages(['hours' => collect($checks['checks'])->firstWhere('ok', false)['label']]);
        }
    }

    private function validateRule(Request $request, bool $preview = false): array
    {
        return $request->validate([
            'agency_id' => ['nullable', 'integer', Rule::exists('agencies', 'id')->where('pressing_id', $request->user()->pressing_id)],
            'service' => ['required', Rule::in(array_keys(RetrievalRule::SERVICES))],
            'hours' => [$preview ? 'nullable' : 'required', 'integer', 'min:1', 'max:720'],
            'status' => ['sometimes', 'boolean'],
            'received_at' => ['nullable', 'date'],
            'ignore_id' => ['nullable', 'integer'],
        ]);
    }

    private function reference(?string $at): CarbonImmutable
    {
        return $at ? CarbonImmutable::parse($at) : CarbonImmutable::now()->startOfHour();
    }

    private function label(RetrievalRule $r): string
    {
        return sprintf('%s · %s · %d heures', $r->agency?->name ?? 'Toutes les agences', RetrievalRule::SERVICES[$r->service] ?? $r->service, $r->hours);
    }
}
