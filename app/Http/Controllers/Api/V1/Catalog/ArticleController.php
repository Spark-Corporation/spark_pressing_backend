<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue')]
class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $articles = Article::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('status', true))
            ->orderBy('name')
            ->paginate(min(100, (int) $request->get('per_page', 50)));

        return $this->page($articles, ArticleResource::collection($articles)->resolve());
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
        ]);

        $data['pressing_id'] = $request->user()->pressing_id;
        $data['status'] = true;

        $article = Article::query()->create($data);

        return $this->created((new ArticleResource($article))->resolve());
    }

    public function show(Article $article): JsonResponse
    {
        return $this->ok((new ArticleResource($article))->resolve());
    }

    public function update(Request $request, Article $article): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'classic_price' => ['sometimes', 'integer', 'min:0'],
            'express_price' => ['sometimes', 'integer', 'min:0'],
            'repass_price' => ['sometimes', 'integer', 'min:0'],
            'classic_price_kilo' => ['sometimes', 'integer', 'min:0'],
            'express_price_kilo' => ['sometimes', 'integer', 'min:0'],
            'repass_price_kilo' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'boolean'],
        ]);

        $article->update($data);

        return $this->ok((new ArticleResource($article))->resolve());
    }

    public function destroy(Article $article): JsonResponse
    {
        $article->delete();

        return $this->ok(['deleted' => true]);
    }
}
