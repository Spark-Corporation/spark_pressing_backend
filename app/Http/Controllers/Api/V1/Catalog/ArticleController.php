<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\LaundryStatus;
use App\Support\Catalog\ArticlePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class ArticleController extends Controller
{
    public function __construct(private readonly ArticlePresenter $presenter) {}

    /** « Catalogue articles et tarifs » (q, category_id, billing_mode, agency_id, tab=all|kilo|unavailable, active_only). */
    public function index(Request $request): JsonResponse
    {
        $agencyCount = Agency::query()->count();
        $articles = Article::query()
            ->with(['category:id,name', 'agencySettings'])
            ->tap(fn ($q) => ArticlePresenter::applyFilters($q, $request->only(['q', 'category_id', 'billing_mode', 'agency_id', 'tab']) + ['active_only' => $request->boolean('active_only')]))
            ->orderBy('name')
            ->paginate(min(100, (int) $request->get('per_page', 50)));

        return $this->page(
            $articles,
            collect($articles->items())->map(fn (Article $a) => $this->presenter->row($a, $agencyCount))->all(),
            ['kpis' => $this->presenter->kpis($request->user()?->pressing_id)]
        );
    }

    /** Onglet « Historique » du catalogue. */
    public function history(): JsonResponse
    {
        return $this->ok($this->presenter->history());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'classic_price' => ['required', 'integer', 'min:0'],
            'express_price' => ['required', 'integer', 'min:0'],
            'repass_price' => ['required', 'integer', 'min:0'],
            'classic_price_kilo' => ['nullable', 'integer', 'min:0'],
            'express_price_kilo' => ['nullable', 'integer', 'min:0'],
            'repass_price_kilo' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ] + self::extraRules());

        $data['pressing_id'] = $request->user()->pressing_id;
        $data['status'] = $data['status'] ?? true;
        $data['price_updated_at'] = now();
        $data['updated_by'] = $request->user()->id;
        $availability = $data['availability'] ?? null;
        unset($data['availability']);

        $article = Article::query()->create($data);
        if (! $article->code) {
            $article->update(['code' => strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $article->name), 0, 3) ?: 'ART').'-'.str_pad((string) $article->id, 3, '0', STR_PAD_LEFT)]);
        }
        $this->syncAvailability($article, $availability);
        AuditLog::record('article.created', $article, ['name' => $article->name]);

        return $this->created($this->presenter->detail($article->fresh()));
    }

    public function show(Article $article): JsonResponse
    {
        return $this->ok($this->presenter->detail($article));
    }

    public function update(Request $request, Article $article): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'classic_price' => ['sometimes', 'integer', 'min:0'],
            'express_price' => ['sometimes', 'integer', 'min:0'],
            'repass_price' => ['sometimes', 'integer', 'min:0'],
            'classic_price_kilo' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'express_price_kilo' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'repass_price_kilo' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ] + self::extraRules());

        $availability = $data['availability'] ?? null;
        unset($data['availability']);
        $priceFields = ['classic_price', 'express_price', 'repass_price', 'classic_price_kilo', 'express_price_kilo', 'repass_price_kilo', 'kilo_tiers'];
        $changed = collect($data)->filter(fn ($v, $k) => $article->{$k} != $v)->keys();
        if ($changed->intersect($priceFields)->isNotEmpty()) {
            $data['price_updated_at'] = now();
        }
        $data['updated_by'] = $request->user()->id;

        $article->update($data);
        $this->syncAvailability($article, $availability);
        AuditLog::record('article.updated', $article, collect($data)->only($changed)->all());

        return $this->ok($this->presenter->detail($article->fresh()));
    }

    /** Champs de l’écran « Création et édition d’article ». */
    private static function extraRules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32'],
            'category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'billing_mode' => ['nullable', 'in:'.implode(',', array_keys(Article::BILLING_MODES))],
            'icon' => ['nullable', 'string', 'max:32'],
            'allow_discount' => ['sometimes', 'boolean'],
            'round_to' => ['nullable', 'integer', 'min:0'],
            'price_editable' => ['sometimes', 'boolean'],
            'classic_hours' => ['nullable', 'integer', 'min:1'],
            'express_hours' => ['nullable', 'integer', 'min:1'],
            'repass_hours' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', 'in:standard,high,delicate'],
            'accepted_defects' => ['nullable', 'array'],
            'accepted_defects.*' => ['string', 'in:'.implode(',', array_keys(LaundryStatus::catalog(request()->user()?->pressing_id)))],
            'compatible_renders' => ['nullable', 'array'],
            'compatible_renders.*' => ['integer'],
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'in:'.implode(',', array_keys(Article::SERVICES))],
            'kilo_tiers' => ['nullable', 'array'],
            'kilo_tiers.*.min_kg' => ['required', 'numeric', 'min:0'],
            'kilo_tiers.*.max_kg' => ['nullable', 'numeric', 'gt:kilo_tiers.*.min_kg'],
            'kilo_tiers.*.classic' => ['required', 'integer', 'min:0'],
            'kilo_tiers.*.express' => ['nullable', 'integer', 'min:0'],
            'availability' => ['nullable', 'array'],
            'availability.*.agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'availability.*.available' => ['required', 'boolean'],
            'availability.*.price_adjust_percent' => ['nullable', 'integer', 'min:-90', 'max:200'],
            'availability.*.note' => ['nullable', 'string', 'max:191'],
        ];
    }

    private function syncAvailability(Article $article, ?array $rows): void
    {
        foreach ($rows ?? [] as $row) {
            $article->agencySettings()->updateOrCreate(
                ['agency_id' => $row['agency_id']],
                [
                    'available' => (bool) $row['available'],
                    'price_adjust_percent' => (int) ($row['price_adjust_percent'] ?? 0),
                    'note' => $row['note'] ?? null,
                ]
            );
        }
    }

    public function destroy(Article $article): JsonResponse
    {
        $article->delete();
        AuditLog::record('article.deleted', $article);

        return $this->ok(['deleted' => true]);
    }
}
