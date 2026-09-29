<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Knuckles\Scribe\Attributes\Group;

#[Group('Organisation', 'Taux de change (consolidation multi-devise).')]
class ExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = ExchangeRate::query()
            ->when($request->source_currency, fn ($q, $c) => $q->where('source_currency', strtoupper($c)))
            ->when($request->target_currency, fn ($q, $c) => $q->where('target_currency', strtoupper($c)))
            ->when($request->rate_date, fn ($q, $d) => $q->whereDate('rate_date', $d))
            ->latest('rate_date')
            ->paginate(min(100, (int) $request->get('per_page', 50)));

        return $this->page($items, $items->items());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3', 'different:source_currency'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'rate_date' => ['required', 'date'],
        ]);

        if (! Currency::isValid($data['source_currency']) || ! Currency::isValid($data['target_currency'])) {
            return response()->json(['message' => 'Code devise ISO 4217 invalide.'], 422);
        }

        $rate = ExchangeRate::query()->updateOrCreate(
            [
                'pressing_id' => $request->user()->pressing_id,
                'source_currency' => Currency::normalize($data['source_currency']),
                'target_currency' => Currency::normalize($data['target_currency']),
                'rate_date' => $data['rate_date'],
            ],
            [
                'rate' => $data['rate'],
                'created_by' => $request->user()->id,
            ]
        );

        return $this->created($rate);
    }

    public function destroy(ExchangeRate $exchangeRate): JsonResponse
    {
        abort_unless((int) $exchangeRate->pressing_id === (int) request()->user()->pressing_id, 403);
        $exchangeRate->delete();

        return $this->ok(['deleted' => true]);
    }
}
