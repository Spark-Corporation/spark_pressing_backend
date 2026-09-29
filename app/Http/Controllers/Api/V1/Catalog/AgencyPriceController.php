<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\AgencyPrice;
use App\Models\Article;
use App\Models\Service;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Knuckles\Scribe\Attributes\Group;

#[Group('Catalogue', 'Tarifs par agence (séparés du catalogue).')]
class AgencyPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = AgencyPrice::query()
            ->with(['article:id,name', 'service:id,name,code', 'agency:id,name,currency'])
            ->when($request->agency_id, fn ($q, $id) => $q->where('agency_id', $id))
            ->when($request->article_id, fn ($q, $id) => $q->where('article_id', $id))
            ->when($request->service_id, fn ($q, $id) => $q->where('service_id', $id))
            ->when($request->boolean('active_only'), function ($q) {
                $today = now()->toDateString();
                $q->where('valid_from', '<=', $today)
                    ->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $today));
            })
            ->latest('valid_from')
            ->paginate(min(100, (int) $request->get('per_page', 50)));

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'article_id' => ['required', 'exists:articles,id'],
            'service_id' => ['required', 'exists:services,id'],
            'pricing_type' => ['nullable', 'in:piece,kilo'],
            'amount_minor' => ['required', 'integer', 'min:0'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $user = $request->user();
        abort_unless($user->canAccessAgency((int) $data['agency_id']), 403);

        Article::query()->findOrFail($data['article_id']);
        Service::query()->findOrFail($data['service_id']);

        $data['pressing_id'] = $user->pressing_id;
        $data['pricing_type'] = $data['pricing_type'] ?? 'piece';

        $this->closeOverlapping($data);

        $price = AgencyPrice::query()->create($data);

        return $this->created($price->load(['article:id,name', 'service:id,name,code']));
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_id' => ['required', 'exists:agencies,id'],
            'article_id' => ['required', 'exists:articles,id'],
            'service_id' => ['required', 'exists:services,id'],
            'pricing_type' => ['nullable', 'in:piece,kilo'],
            'at' => ['nullable', 'date'],
        ]);

        abort_unless($request->user()->canAccessAgency((int) $data['agency_id']), 403);

        $amount = AgencyPrice::resolveAmount(
            (int) $data['agency_id'],
            (int) $data['article_id'],
            (int) $data['service_id'],
            $data['pricing_type'] ?? 'piece',
            isset($data['at']) ? now()->parse($data['at']) : now()
        );

        if ($amount === null) {
            throw ValidationException::withMessages([
                'amount_minor' => 'Aucun tarif agence actif pour cette combinaison.',
            ]);
        }

        $agency = \App\Models\Agency::query()->findOrFail($data['agency_id']);

        return $this->ok([
            'amount_minor' => $amount,
            'currency' => Currency::normalize($agency->currency ?: config('spark.currency')),
        ]);
    }

    private function closeOverlapping(array $data): void
    {
        $from = $data['valid_from'];

        AgencyPrice::query()
            ->where('agency_id', $data['agency_id'])
            ->where('article_id', $data['article_id'])
            ->where('service_id', $data['service_id'])
            ->where('pricing_type', $data['pricing_type'])
            ->whereNull('valid_to')
            ->where('valid_from', '<', $from)
            ->update(['valid_to' => now()->parse($from)->subDay()->toDateString()]);
    }
}
