<?php

use App\Http\Controllers\Api\V1\Reporting\ReportController;
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
