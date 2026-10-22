<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ArticleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Knuckles\Scribe\Attributes\Group;

/** Onglet « Catégories » du catalogue. */
#[Group('Catalogue')]
class ArticleCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->ok(ArticleCategory::query()
            ->withCount(['articles', 'articles as active_articles_count' => fn ($q) => $q->where('status', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ArticleCategory $c) => $this->payload($c))
            ->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));
        $category = ArticleCategory::query()->create($data + ['pressing_id' => $request->user()->pressing_id]);

        return $this->created($this->payload($category->loadCount('articles')));
    }

    public function update(Request $request, ArticleCategory $articleCategory): JsonResponse
    {
        $articleCategory->update($request->validate($this->rules($request, $articleCategory)));

        return $this->ok($this->payload($articleCategory->loadCount('articles')));
    }

    public function destroy(ArticleCategory $articleCategory): JsonResponse
    {
        $articleCategory->delete();

        return $this->ok(['deleted' => true]);
    }

    private function rules(Request $request, ?ArticleCategory $category = null): array
    {
        return [
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:191',
                Rule::unique('article_categories', 'name')->where('pressing_id', $request->user()->pressing_id)->ignore($category?->id)],
            'icon' => ['nullable', 'string', 'max:32'],
            'shared' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function payload(ArticleCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'icon' => $c->icon,
            'shared' => (bool) $c->shared,
            'sort_order' => (int) $c->sort_order,
            'articles_count' => (int) ($c->articles_count ?? 0),
            'active_articles_count' => (int) ($c->active_articles_count ?? $c->articles_count ?? 0),
        ];
    }
}
