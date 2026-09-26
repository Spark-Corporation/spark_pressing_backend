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
            ->with(['client:id,fullname,phone_number,code'])
            ->withSum('units as items_count', 'quantity')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->toString();
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($c) => $c->where('fullname', 'like', "%{$q}%")
                            ->orWhere('phone_number', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('etat'), fn ($q) => $q->where('etat', $request->etat))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('deposit_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('deposit_date', '<=', $request->date_to))
            ->when($request->boolean('due'), fn ($q) => self::due($q))
            ->when($request->boolean('overdue'), fn ($q) => self::overdue($q))
            ->when($request->boolean('discounted'), fn ($q) => $q->where('discount', '>', 0));
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
            ->with(['client:id,fullname,phone_number,code'])
            ->withSum('units as items_count', 'quantity')
            ->whereNotNull('retrieved_at')
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('retrieved_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('retrieved_at', '<=', $request->date_to))
            ->when($request->boolean('today') || (! $request->filled('date_from') && ! $request->filled('date_to')), function ($q) {
                $q->whereDate('retrieved_at', now()->toDateString());
            });
    }
}
