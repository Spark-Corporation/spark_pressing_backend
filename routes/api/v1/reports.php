<?php

use App\Http\Controllers\Api\V1\Reporting\NetworkController;
use App\Http\Controllers\Api\V1\Reporting\ReportController;
use App\Http\Controllers\Api\V1\Reporting\ReportScreenController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->controller(ReportController::class)->group(function () {
    Route::get('sales', 'sales')->name('sales');
    Route::get('cashiers', 'cashiers')->name('cashiers');
    Route::get('clients', 'clients')->name('clients');
    Route::get('ranking', 'ranking')->name('ranking');
    Route::get('discounts', 'discounts')->name('discounts');
    Route::get('daily-balance', 'dailyBalance')->name('daily-balance');
    Route::get('orders', 'orders')->name('orders');
    Route::get('consolidated', 'consolidated')->middleware('permission:reports.consolidated')->name('consolidated');
});

Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->controller(ReportScreenController::class)->group(function () {
    Route::get('overview', 'overview')->name('overview');
    Route::get('daily', 'daily')->name('daily');
    Route::get('schedules', 'schedules')->name('schedules.index');
    Route::post('schedules', 'storeSchedule')->name('schedules.store');
    Route::delete('schedules/{reportSchedule}', 'destroySchedule')->name('schedules.destroy');
});

// Écrans « 10 — Multi-agences ».
Route::prefix('network')->name('network.')->middleware('permission:reports.view')->controller(NetworkController::class)->group(function () {
    Route::get('overview', 'overview')->middleware('permission:reports.consolidated')->name('overview');
    Route::get('agencies/{agency}', 'agency')->name('agency');
});
