<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\CodeSuffix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

/**
 * Écrans Figma « 17.01 — Suffixes des codes dépôt » : un suffixe actif par agence, appliqué aux
 * futurs dépôts uniquement (aucune renumérotation). Le suffixe actif est recopié dans `agencies.code_suffix`.
 */
#[Group('Catalogue')]
class CodeSuffixController extends TenantResourceController
{
    public const NOTICE = 'Aucune renumérotation des dépôts existants : le nouveau format concerne uniquement les futurs dépôts.';

    protected function model(): string
    {
        return CodeSuffix::class;
    }

    protected function rules(bool $update = false): array
    {
        return [
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:8'],
            'agency_id' => [$update ? 'sometimes' : 'required', 'integer'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /** « Suffixes configurés » : une ligne par agence (préfixe, suffixe, exemple de code complet), `q`, `agency_id`. */
    public function index(): JsonResponse
    {
        $request = request();
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'agency_id' => ['nullable', 'integer']]);
        $agencies = Agency::query()->where('pressing_id', $request->user()->pressing_id)->orderBy('name')->get();
        $suffixes = CodeSuffix::query()->where('status', true)->latest('id')->get()->unique('agency_id')->keyBy('agency_id');

        $rows = $agencies->map(fn (Agency $a) => $this->row($a, $suffixes->get($a->id)))
            ->when($data['agency_id'] ?? null, fn ($c, $id) => $c->where('agency_id', (int) $id))
            ->when($data['q'] ?? null, fn ($c, $q) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['agency'].' '.$r['prefix'].' '.$r['suffix'].' '.$r['example_code']), mb_strtolower($q))))
            ->values();

        return $this->ok($rows->all(), [
            'agencies' => $agencies->count(),
            'configured' => $rows->whereNotNull('suffix')->count(),
            'results_label' => $rows->count().' résultat'.($rows->count() > 1 ? 's' : ''),
            'notice' => self::NOTICE,
        ]);
    }

    /** « Aperçu du code » et « Vérifications » avant enregistrement (`agency_id`, `suffix`). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['agency_id' => ['required', 'integer'], 'suffix' => ['nullable', 'string', 'max:12'], 'ignore_id' => ['nullable', 'integer']]);
        $agency = $this->agency((int) $data['agency_id'], (int) $request->user()->pressing_id);
        $suffix = self::normalize($data['suffix'] ?? '');

        return $this->ok($this->checks($agency, $suffix, $data['ignore_id'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $agency = $this->agency((int) $data['agency_id'], (int) $request->user()->pressing_id);
        $suffix = $this->validated($agency, $data['title']);

        $item = CodeSuffix::query()->create([
            'pressing_id' => $request->user()->pressing_id,
            'agency_id' => $agency->id,
            'title' => $suffix,
            'status' => $data['status'] ?? true,
        ]);
        $this->syncAgency($agency);
        AuditLog::record('code_suffix.created', $agency, ['suffix' => $suffix]);

        return $this->created($this->row($agency->fresh(), $item), ['notice' => self::NOTICE]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = CodeSuffix::query()->findOrFail($id);
        $data = $request->validate($this->rules(true));
        $agency = $this->agency((int) ($data['agency_id'] ?? $item->agency_id), (int) $request->user()->pressing_id);
        $before = $item->title;
        if (isset($data['title'])) {
            $data['title'] = $this->validated($agency, $data['title'], $item->id);
        }
        $item->update($data + ['agency_id' => $agency->id]);
        $this->syncAgency($agency);
        AuditLog::record('code_suffix.updated', $agency, ['from' => $before, 'to' => $item->title]);

        return $this->ok($this->row($agency->fresh(), $item->status ? $item : null), ['notice' => self::NOTICE]);
    }

    /** « Supprimer le suffixe » : les futurs dépôts utiliseront le préfixe seul ; les codes existants ne changent pas. */
    public function destroy(int $id): JsonResponse
    {
        $item = CodeSuffix::query()->findOrFail($id);
        $agency = $this->agency((int) $item->agency_id, (int) request()->user()->pressing_id);
        $item->delete();
        $this->syncAgency($agency);
        AuditLog::record('code_suffix.deleted', $agency, ['suffix' => $item->title]);

        return $this->ok([
            'deleted' => true,
            'agency' => $this->row($agency->fresh(), null),
            'message' => sprintf('Les prochains dépôts de %s utiliseront le préfixe %s et leur numéro, sans suffixe. Les dépôts existants gardent leur code et leur historique.', $agency->name, $agency->nextCodePrefix()),
        ]);
    }

    private function row(Agency $agency, ?CodeSuffix $suffix): array
    {
        $title = $suffix?->title ?? $agency->code_suffix;

        return [
            'agency_id' => $agency->id,
            'agency' => $agency->name,
            'prefix' => $agency->nextCodePrefix(),
            'suffix_id' => $suffix?->id,
            'suffix' => $title,
            'suffix_label' => $title ? '-'.$title : null,
            'example_code' => self::example($agency, $title),
            'next_code' => self::example($agency, $title, (int) $agency->last_deposit_sequence + 1),
        ];
    }

    /** Même format que la création de dépôt (CreateDeposit). */
    public static function example(Agency $agency, ?string $suffix, int $number = 2684): string
    {
        return sprintf('%s-%s%s', $agency->nextCodePrefix(), str_pad((string) $number, 6, '0', STR_PAD_LEFT), $suffix ? '-'.$suffix : '');
    }

    private static function normalize(string $suffix): string
    {
        return strtoupper(ltrim(trim($suffix), '-'));
    }

    private function checks(Agency $agency, string $suffix, ?int $ignoreId = null): array
    {
        $format = (bool) preg_match('/^[A-Z0-9]{1,6}$/', $suffix);
        $taken = CodeSuffix::query()->where('agency_id', $agency->id)->where('status', true)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        $clash = $suffix !== '' && $suffix === strtoupper((string) $agency->nextCodePrefix());

        return [
            'suffix' => $suffix,
            'preview' => self::example($agency, $suffix ?: null),
            'checks' => [
                ['code' => 'format', 'ok' => $format, 'label' => '1 à 6 caractères, lettres majuscules et chiffres'],
                ['code' => 'unique', 'ok' => ! $taken, 'label' => $taken ? 'Cette agence a déjà un suffixe : modifiez-le' : 'Aucun autre suffixe actif pour cette agence'],
                ['code' => 'prefix', 'ok' => ! $clash, 'label' => 'Différent du préfixe de l’agence'],
                ['code' => 'no_renumbering', 'ok' => true, 'label' => 'Les codes déjà créés restent inchangés'],
            ],
            'valid' => $format && ! $taken && ! $clash,
        ];
    }

    private function validated(Agency $agency, string $title, ?int $ignoreId = null): string
    {
        $checks = $this->checks($agency, self::normalize($title), $ignoreId);
        if (! $checks['valid']) {
            throw ValidationException::withMessages(['title' => collect($checks['checks'])->firstWhere('ok', false)['label']]);
        }

        return $checks['suffix'];
    }

    private function syncAgency(Agency $agency): void
    {
        $agency->update(['code_suffix' => CodeSuffix::query()->where('agency_id', $agency->id)->where('status', true)->latest('id')->value('title')]);
    }

    private function agency(int $agencyId, int $pressingId): Agency
    {
        $agency = Agency::query()->where('id', $agencyId)->where('pressing_id', $pressingId)->first();
        abort_unless($agency, 403);

        return $agency;
    }
}
