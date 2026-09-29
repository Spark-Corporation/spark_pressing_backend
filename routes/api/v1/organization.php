<?php

use App\Http\Controllers\Api\V1\Ops\AuditLogController;
use App\Http\Controllers\Api\V1\Settings\ExchangeRateController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Tenancy\PressingAgencyController;
use App\Http\Controllers\Api\V1\Tenancy\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('pressing', [SettingsController::class, 'pressing'])->name('pressing');
    Route::put('pressing', [SettingsController::class, 'updatePressing'])->middleware('permission:settings.manage')->name('pressing.update');
    Route::put('agencies/{agency}', [SettingsController::class, 'updateAgency'])->middleware('permission:agencies.manage')->name('agencies.update');
    Route::get('license', [SettingsController::class, 'license'])->name('license');

    Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:fx.manage|reports.consolidated')->name('fx.index');
    Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->middleware('permission:fx.manage')->name('fx.store');
    Route::delete('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy'])->middleware('permission:fx.manage')->name('fx.destroy');
});

Route::prefix('org')->name('org.')->middleware('permission:agencies.manage')->group(function () {
    Route::get('agencies', [PressingAgencyController::class, 'agencies'])->name('agencies');
    Route::post('agencies', [PressingAgencyController::class, 'storeAgency'])->name('agencies.store');
    Route::get('users', [PressingAgencyController::class, 'users'])->name('users');
    Route::post('users', [PressingAgencyController::class, 'storeUser'])->name('users.store');
    Route::put('users/{user}', [PressingAgencyController::class, 'updateUser'])->name('users.update');
    Route::delete('users/{user}', [PressingAgencyController::class, 'destroyUser'])->name('users.destroy');
});

Route::middleware('permission:roles.manage')->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
    Route::put('roles/{role}', [RoleController::class, 'sync'])->name('roles.sync');
});

Route::middleware('permission:audit.view')->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('print-logs', [AuditLogController::class, 'prints'])->name('prints.index');
});
