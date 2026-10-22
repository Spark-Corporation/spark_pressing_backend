<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\AuditLog;
use App\Models\DepositUnit;
use App\Models\LaundryStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

/**
 * Écrans Figma « 17.03 — États du linge » : défauts proposés à la réception d’un dépôt.
 * Un état déjà utilisé n’est jamais supprimé : il est désactivé et reste lisible dans l’historique.
 */
#[Group('Catalogue')]
class LaundryStatusController extends TenantResourceController
{
    public const NOTICE = 'Un état utilisé n’est pas supprimé : désactivez-le pour les prochaines réceptions. Les constats déjà enregistrés restent consultables dans l’historique des dépôts.';

    public const CONFIDENTIALITY = 'Les remarques et détails internes sont réservés aux réceptionnistes : ils ne sont jamais imprimés ni exposés au portail client.';

    protected function model(): string
    {
        return LaundryStatus::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:191'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** « Défauts à la réception » (`q`, `availability=active|inactive`) avec l’usage dans les dépôts. */
    public function index(): JsonResponse
    {
        $request = request();
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'availability' => ['nullable', 'in:active,inactive']]);
        LaundryStatus::ensureDefaults((int) $request->user()->pressing_id);
        $all = LaundryStatus::query()->whereNotNull('code')->orderBy('sort_order')->orderBy('id')->get();

        $rows = $all->map(fn (LaundryStatus $s) => $this->row($s))
            ->when($data['availability'] ?? null, fn ($c, $a) => $c->where('status', $a))
            ->when($data['q'] ?? null, fn ($c, $q) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['title'].' '.$r['description']), mb_strtolower($q))))
            ->values();

        return $this->ok($rows->all(), [
            'total' => $all->count(),
            'active' => $all->where('status', true)->count(),
            'summary_label' => sprintf('%d états · %d actifs', $all->count(), $all->where('status', true)->count()),
            'results_label' => $rows->count().' résultat'.($rows->count() > 1 ? 's' : ''),
            'notice' => self::NOTICE,
            'confidentiality' => self::CONFIDENTIALITY,
        ]);
    }

    /** Vérifications du formulaire « Ajouter un état du linge » (`title`, `ignore_id`). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:60'], 'ignore_id' => ['nullable', 'integer']]);

        return $this->ok($this->checks((string) ($data['title'] ?? ''), $data['ignore_id'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        LaundryStatus::ensureDefaults((int) $request->user()->pressing_id);
        $this->assertValid($data['title']);

        $status = LaundryStatus::query()->create([
            'pressing_id' => $request->user()->pressing_id,
            'title' => trim($data['title']),
            'code' => $this->uniqueCode($data['title']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? true,
            'sort_order' => $data['sort_order'] ?? (int) LaundryStatus::query()->max('sort_order') + 1,
        ]);
        AuditLog::record('laundry_status.created', $status, ['title' => $status->title]);

        return $this->created($this->row($status), ['notice' => self::NOTICE]);
    }

    /** Modifier le libellé, la description ou la disponibilité (le code technique ne change jamais). */
    public function update(Request $request, int $id): JsonResponse
    {
        $status = LaundryStatus::query()->findOrFail($id);
        $data = $request->validate($this->rules(true));
        if (isset($data['title'])) {
            $this->assertValid($data['title'], $status->id);
            $data['title'] = trim($data['title']);
        }
        $before = $status->only(['title', 'status']);
        $status->update($data);
        AuditLog::record(isset($data['status']) && ! $data['status'] ? 'laundry_status.disabled' : 'laundry_status.updated', $status, ['before' => $before, 'after' => $status->only(['title', 'status'])]);

        return $this->ok($this->row($status), [
            'message' => isset($data['status']) && ! $data['status']
                ? sprintf('« %s » ne sera plus proposé pour les nouveaux constats. L’historique (%s) reste inchangé.', $status->title, $this->row($status)['usage_label'])
                : null,
        ]);
    }

    /** Suppression possible uniquement si l’état n’a jamais été utilisé. */
    public function destroy(int $id): JsonResponse
    {
        $status = LaundryStatus::query()->findOrFail($id);
        if ($this->usage($status) > 0) {
            throw ValidationException::withMessages(['id' => self::NOTICE]);
        }
        $status->delete();
        AuditLog::record('laundry_status.deleted', null, ['title' => $status->title]);

        return $this->ok(['deleted' => true]);
    }

    private function row(LaundryStatus $s): array
    {
        $usage = $this->usage($s);

        return [
            'id' => $s->id,
            'code' => $s->code,
            'title' => $s->title,
            'description' => $s->description,
            'usage' => $usage,
            'usage_label' => $usage.' dépôt'.($usage > 1 ? 's' : ''),
            'status' => $s->status ? 'active' : 'inactive',
            'status_label' => $s->status ? 'Actif' : 'Inactif',
            'deletable' => $usage === 0,
            'sort_order' => $s->sort_order,
        ];
    }

    /** Nombre de dépôts dont une pièce porte ce défaut. */
    private function usage(LaundryStatus $s): int
    {
        return DepositUnit::query()->withoutGlobalScopes()->where('pressing_id', $s->pressing_id)
            ->where('defects', 'like', '%"'.$s->code.'"%')->distinct()->count('deposit_id');
    }

    private function checks(string $title, ?int $ignoreId = null): array
    {
        $title = trim($title);
        $taken = $title !== '' && LaundryStatus::query()->whereRaw('lower(title) = ?', [mb_strtolower($title)])->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        $length = mb_strlen($title) >= 2 && mb_strlen($title) <= 60;

        return [
            'title' => $title,
            'checks' => [
                ['code' => 'length', 'ok' => $length, 'label' => 'Libellé de 2 à 60 caractères'],
                ['code' => 'unique', 'ok' => ! $taken, 'label' => $taken ? 'Ce libellé existe déjà' : 'Libellé disponible'],
                ['code' => 'history', 'ok' => true, 'label' => 'Les constats déjà enregistrés ne changent pas'],
            ],
            'valid' => $length && ! $taken,
        ];
    }

    private function assertValid(string $title, ?int $ignoreId = null): void
    {
        $checks = $this->checks($title, $ignoreId);
        if (! $checks['valid']) {
            throw ValidationException::withMessages(['title' => collect($checks['checks'])->firstWhere('ok', false)['label']]);
        }
    }

    private function uniqueCode(string $title): string
    {
        $base = LaundryStatus::codeFor($title) ?: 'etat';
        $code = $base;
        $i = 2;
        while (LaundryStatus::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
