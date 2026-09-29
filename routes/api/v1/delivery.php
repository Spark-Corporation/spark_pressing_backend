<?php

use App\Http\Controllers\Api\V1\Delivery\DeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('deliveries')->name('deliveries.')->group(function () {
    Route::get('queue', [DeliveryController::class, 'queue'])->middleware('permission:deliveries.view')->name('queue');
    Route::get('history', [DeliveryController::class, 'history'])->middleware('permission:deliveries.view')->name('history');

    Route::get('zones', [DeliveryController::class, 'zonesIndex'])->middleware('permission:deliveries.view')->name('zones.index');
    Route::post('zones', [DeliveryController::class, 'zonesStore'])->middleware('permission:deliveries.manage')->name('zones.store');
    Route::put('zones/{zone}', [DeliveryController::class, 'zonesUpdate'])->middleware('permission:deliveries.manage')->name('zones.update');

    Route::get('rounds', [DeliveryController::class, 'roundsIndex'])->middleware('permission:deliveries.view')->name('rounds.index');
    Route::post('rounds', [DeliveryController::class, 'roundsStore'])->middleware('permission:deliveries.manage')->name('rounds.store');
    Route::post('rounds/{round}/assign', [DeliveryController::class, 'assign'])->middleware(['permission:deliveries.manage', 'idempotent'])->name('rounds.assign');

    Route::post('deposits/{deposit}/schedule', [DeliveryController::class, 'scheduleDeposit'])->middleware(['permission:deliveries.manage', 'idempotent'])->name('schedule');
    Route::post('deposits/{deposit}/deliver', [DeliveryController::class, 'markDelivered'])->middleware(['permission:deliveries.collect', 'idempotent'])->name('deliver');
});
