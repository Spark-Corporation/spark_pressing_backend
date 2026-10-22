<?php

namespace App\Support\Catalog;

use App\Http\Resources\ArticleResource;
use App\Models\Agency;
use App\Models\Article;
use App\Models\ArticleAgencySetting;
use App\Models\ArticleCategory;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Render;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Écrans Figma « Catalogue articles et tarifs » et « Création et édition d’article ».
 */
class ArticlePresenter
{
    public function kpis(?int $pressingId): array
    {
        $active = Article::query()->where('status', true);
        $avg = (float) (clone $active)->avg('classic_price');
        $avgBefore = (float) Article::query()->where('status', true)->where('created_at', '<', now()->startOfMonth())->avg('classic_price');

        return [
            'active_articles' => ['value' => (clone $active)->count(), 'new_this_month' => (clone $active)->where('created_at', '>=', now()->startOfMonth())->count()],
            'categories' => ['value' => ArticleCategory::query()->count(), 'shared' => ArticleCategory::query()->where('shared', true)->count()],
            'average_price' => ['value' => (int) round($avg), 'delta_percent' => $avgBefore > 0 ? round(($avg - $avgBefore) / $avgBefore * 100, 1) : null],
            'to_review' => [
                'value' => (clone $active)->where(fn ($q) => $q->where('price_updated_at', '<', now()->subYear())
                    ->orWhere(fn ($w) => $w->whereNull('price_updated_at')->where('updated_at', '<', now()->subYear())))->count(),
                'rule' => 'Tarifs > 12 mois',
            ],
            'updated_at' => Article::query()->max('updated_at'),
        ];
    }

    public static function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('articles.name', 'like', "%{$term}%")
                ->orWhere('articles.code', 'like', "%{$term}%")
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['billing_mode'] ?? null, fn ($q, $mode) => $q->where('billing_mode', $mode))
            ->when($filters['agency_id'] ?? null, fn ($q, $agency) => $q->whereDoesntHave('agencySettings', fn ($s) => $s->where('agency_id', $agency)->where('available', false)))
            ->when(($filters['tab'] ?? null) === 'unavailable', fn ($q) => $q->where(fn ($w) => $w->where('status', false)->orWhereHas('agencySettings', fn ($s) => $s->where('available', false))))
            ->when(($filters['tab'] ?? null) === 'kilo', fn ($q) => $q->whereIn('billing_mode', ['kilo', 'mixed']))
            ->when(isset($filters['active_only']) && $filters['active_only'], fn ($q) => $q->where('status', true));
    }

    public function row(Article $article, int $agencyCount): array
    {
        return (new ArticleResource($article))->resolve() + [
            'billing_mode_label' => Article::BILLING_MODES[$article->billing_mode] ?? $article->billing_mode,
            'scope' => $this->scope($article, $agencyCount),
        ];
    }

    public function detail(Article $article): array
    {
        $article->loadMissing(['category', 'agencySettings', 'editor:id,fullname']);
        $agencies = Agency::query()->orderBy('name')->get(['id', 'name', 'status']);
        $settings = $article->agencySettings->keyBy('agency_id');
        $last = AuditLog::query()->where('subject_type', Article::class)->where('subject_id', $article->id)->latest('id')->first();
        $lastBy = $last?->actor_id ? User::query()->whereKey($last->actor_id)->value('fullname') : null;

        $availability = $agencies->map(function (Agency $agency) use ($settings) {
            /** @var ArticleAgencySetting|null $s */
            $s = $settings->get($agency->id);
            $available = $agency->status && ($s?->available ?? true);

            return [
                'agency_id' => $agency->id,
                'agency' => $agency->name,
                'available' => $available,
                'status' => $available ? ['code' => 'available', 'label' => 'Disponible'] : ['code' => 'unavailable', 'label' => 'Indisponible'],
                'price_adjust_percent' => (int) ($s?->price_adjust_percent ?? 0),
                'note' => $s?->note ?? ((int) ($s?->price_adjust_percent ?? 0) !== 0 ? sprintf('Prix local %+d%%', $s->price_adjust_percent) : 'Catalogue principal'),
            ];
        })->values();

        return $this->row($article, $agencies->count()) + [
            'kilo_tiers' => $article->kilo_tiers ?? [],
            'availability' => [
                'active' => $availability->where('available', true)->count(),
                'total' => $agencies->where('status', true)->count(),
                'agencies' => $availability->all(),
            ],
            'preview' => [
                'title' => $article->name,
                'subtitle' => collect([$article->code, $article->classic_hours ? "Délai {$article->classic_hours} h" : null])->filter()->implode(' · '),
                'badge' => collect([$article->category?->name, Article::BILLING_MODES[$article->billing_mode] ?? null])->filter()->implode(' · '),
                'prices' => [
                    ['label' => 'Classique', 'amount' => (int) $article->classic_price],
                    ['label' => 'Express', 'amount' => (int) $article->express_price],
                    ['label' => 'Repassage', 'amount' => (int) $article->repass_price],
                ],
            ],
            'checks' => $this->checks($article, $availability),
            'recommendations' => $this->recommendations($article),
            'last_modification' => $last ? ['by' => $lastBy, 'at' => $last->created_at?->toIso8601String()] : null,
            'options' => [
                'defects' => collect(Deposit::DEFECTS)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
                'renders' => Render::query()->where('status', true)->get(['id', 'title']),
                'services' => collect(Article::SERVICES)->map(fn ($l, $c) => ['code' => $c, 'label' => $l])->values(),
                'priorities' => [['code' => 'standard', 'label' => 'Standard'], ['code' => 'high', 'label' => 'Haute'], ['code' => 'delicate', 'label' => 'Délicat']],
            ],
        ];
    }

    /** Historique des modifications du catalogue. */
    public function history(int $limit = 50): array
    {
        $logs = AuditLog::query()
            ->where('subject_type', Article::class)
            ->latest('id')
            ->limit($limit)
            ->get();
        $names = Article::withTrashed()->whereIn('id', $logs->pluck('subject_id'))->pluck('name', 'id');
        $actors = User::query()->whereIn('id', $logs->pluck('actor_id')->filter())->pluck('fullname', 'id');

        return $logs->map(fn (AuditLog $l) => [
            'at' => $l->created_at?->toIso8601String(),
            'article_id' => $l->subject_id,
            'article' => $names[$l->subject_id] ?? null,
            'action' => $l->action,
            'label' => ['article.created' => 'Article créé', 'article.updated' => 'Article modifié', 'article.deleted' => 'Article supprimé'][$l->action] ?? $l->action,
            'changes' => array_keys($l->payload ?? []),
            'by' => $actors[$l->actor_id] ?? null,
        ])->all();
    }

    private function scope(Article $article, int $agencyCount): array
    {
        $settings = $article->relationLoaded('agencySettings') ? $article->agencySettings : $article->agencySettings()->get();
        $unavailable = $settings->where('available', false)->count();
        $label = match (true) {
            ! $article->status => 'Désactivé',
            $unavailable === 0 => 'Partagé',
            $agencyCount - $unavailable === 1 => (string) Agency::query()->whereKey(
                Agency::query()->pluck('id')->diff($settings->where('available', false)->pluck('agency_id'))->first()
            )->value('name'),
            default => ($agencyCount - $unavailable).' agences',
        };

        return [
            'status' => $article->status ? ['code' => 'active', 'label' => 'Actif'] : ['code' => 'inactive', 'label' => 'Inactif'],
            'label' => $label,
            'agencies' => max(0, $agencyCount - $unavailable),
        ];
    }

    private function checks(Article $article, Collection $availability): array
    {
        $prices = $article->billing_mode === 'kilo'
            ? [(int) $article->classic_price_kilo, (int) $article->express_price_kilo]
            : [(int) $article->classic_price, (int) $article->express_price];

        return [
            ['code' => 'required', 'label' => 'Informations obligatoires', 'ok' => filled($article->name) && filled($article->category_id)],
            ['code' => 'prices', 'label' => 'Tarifs cohérents', 'ok' => $prices[0] > 0 && $prices[1] >= $prices[0]],
            ['code' => 'delays', 'label' => 'Délais renseignés', 'ok' => (bool) $article->classic_hours && (bool) $article->express_hours],
            ['code' => 'agency', 'label' => 'Une agence active', 'ok' => $availability->where('available', true)->isNotEmpty()],
        ];
    }

    private function recommendations(Article $article): array
    {
        $out = [];
        if ($article->classic_price > 0 && $article->express_price > $article->classic_price * 1.4) {
            $pct = (int) round(($article->express_price - $article->classic_price) / $article->classic_price * 100);
            $out[] = ['code' => 'express_gap', 'label' => "Le tarif Express est supérieur de {$pct}%. Vérifiez le catalogue partagé."];
        }

        return $out;
    }
}
