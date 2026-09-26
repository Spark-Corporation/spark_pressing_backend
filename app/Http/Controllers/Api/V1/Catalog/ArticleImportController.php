<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Catalogue')]
class ArticleImportController extends Controller
{
    public function export(): StreamedResponse
    {
        $filename = 'articles-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'name', 'description', 'classic_price', 'express_price', 'repass_price',
                'classic_price_kilo', 'express_price_kilo', 'repass_price_kilo', 'status',
            ]);

            Article::query()->orderBy('name')->each(function (Article $article) use ($out) {
                fputcsv($out, [
                    $article->name,
                    $article->description,
                    $article->classic_price,
                    $article->express_price,
                    $article->repass_price,
                    $article->classic_price_kilo,
                    $article->express_price_kilo,
                    $article->repass_price_kilo,
                    $article->status ? 1 : 0,
                ]);
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle) ?: [];
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $created = 0;
        $updated = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if (! $data || empty($data['name'])) {
                continue;
            }

            $payload = [
                'description' => $data['description'] ?? null,
                'classic_price' => (int) ($data['classic_price'] ?? 0),
                'express_price' => (int) ($data['express_price'] ?? 0),
                'repass_price' => (int) ($data['repass_price'] ?? 0),
                'classic_price_kilo' => (int) ($data['classic_price_kilo'] ?? 0),
                'express_price_kilo' => (int) ($data['express_price_kilo'] ?? 0),
                'repass_price_kilo' => (int) ($data['repass_price_kilo'] ?? 0),
                'status' => ((int) ($data['status'] ?? 1)) === 1,
                'pressing_id' => $request->user()->pressing_id,
            ];

            $article = Article::query()->where('name', $data['name'])->first();
            if ($article) {
                $article->update($payload);
                $updated++;
            } else {
                Article::query()->create($payload + ['name' => $data['name']]);
                $created++;
            }
        }

        fclose($handle);

        return $this->ok([
            'created' => $created,
            'updated' => $updated,
        ]);
    }

    public function template(): JsonResponse
    {
        return $this->ok([
            'columns' => [
                'name', 'description', 'classic_price', 'express_price', 'repass_price',
                'classic_price_kilo', 'express_price_kilo', 'repass_price_kilo', 'status',
            ],
            'example' => ArticleResource::make(new Article([
                'name' => 'Chemise',
                'classic_price' => 500,
                'express_price' => 800,
                'repass_price' => 300,
            ]))->resolve(),
        ]);
    }
}
