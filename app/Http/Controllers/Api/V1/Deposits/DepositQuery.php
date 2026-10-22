<?php

namespace App\Http\Controllers\Api\V1\Deposits;

use App\Models\Deposit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class DepositQuery
{
    public static function filtered(Request $request): Builder
    {
        return Deposit::query()
            ->select('deposits.*')
            ->with([
                'client:id,fullname,phone_number,code',
                'agency:id,name',
            ])
            ->withSum('units as items_count', 'quantity')
            ->tap(fn (Builder $q) => self::withListAggregates($q))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->toString();
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('fullname', 'like', "%{$q}%")
                            ->orWhere('phone_number', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('etat'), fn ($q) => $request->etat === 'retrieved'
                ? $q->whereNotNull('retrieved_at')
                : $q->where('etat', $request->etat)->whereNull('retrieved_at'))
            ->when($request->filled('payment_status'), fn ($q) => match ($request->payment_status) {
                'paid' => $q->where('left_to_pay', 0),
                'partial' => $q->where('left_to_pay', '>', 0)->where('advanced', '>', 0),
                'unpaid' => $q->where('left_to_pay', '>', 0)->where('advanced', 0),
                default => $q,
            })
            ->when($request->filled('agency_id'), fn ($q) => $q->where('deposits.agency_id', $request->integer('agency_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('period') && ! $request->filled('date_from'), function ($q) use ($request) {
                $from = match ($request->period) {
                    'week' => now()->startOfWeek(),
                    'month' => now()->startOfMonth(),
                    default => now()->startOfDay(),
                };
                $q->where('deposit_date', '>=', $from);
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('deposit_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('deposit_date', '<=', $request->date_to))
            ->when($request->boolean('due'), fn ($q) => self::due($q))
            ->when($request->boolean('overdue'), fn ($q) => self::overdue($q))
            ->when($request->boolean('discounted'), fn ($q) => $q->where('discount', '>', 0));
    }

    /** Agrégats pour les colonnes « Prestation » et « Articles / poids ». */
    public static function withListAggregates(Builder $query): Builder
    {
        return $query
            ->withSum(['units as pieces_count' => fn ($q) => $q->where('pricing_type', 'piece')], 'quantity')
            ->withSum(['units as weight_total' => fn ($q) => $q->where('pricing_type', 'kilo')], 'weight_kg')
            ->withMin('units as min_type_action', 'type_action')
            ->withMax('units as max_type_action', 'type_action');
    }

    public static function due(Builder $query): Builder
    {
        return $query
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->whereNotNull('retrieve_date')
            ->where('retrieve_date', '<=', now());
    }

    public static function overdue(Builder $query): Builder
    {
        return $query
            ->where('status', true)
            ->whereNull('retrieved_at')
            ->whereNotNull('retrieve_date')
            ->where('retrieve_date', '<', now());
    }

    public static function retrieved(Request $request): Builder
    {
        return Deposit::query()
            ->select('deposits.*')
            ->with([
                'client:id,fullname,phone_number,code',
                'agency:id,name',
            ])
            ->withSum('units as items_count', 'quantity')
            ->whereNotNull('retrieved_at')
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('retrieved_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('retrieved_at', '<=', $request->date_to))
            ->when($request->boolean('today') || (! $request->filled('date_from') && ! $request->filled('date_to')), function ($q) {
                $q->whereDate('retrieved_at', now()->toDateString());
            });
    }
}
