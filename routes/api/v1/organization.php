<?php

use App\Http\Controllers\Api\V1\Ops\AuditLogController;
use App\Http\Controllers\Api\V1\Settings\ExchangeRateController;
use App\Http\Controllers\Api\V1\Settings\PressingSettingsController;
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

    // Écrans « 09 — Paramètres » : hub, catégories (config/settings_schema.php), journal, appareils de caisse.
    Route::controller(PressingSettingsController::class)->group(function () {
        $categories = array_keys(config('settings_schema.categories'));
        Route::get('/', 'index')->name('hub');
        Route::get('journal', 'journal')->name('journal');
        Route::get('devices', 'devices')->name('devices.index');
        Route::get('notifications/logs', 'notificationLogs')->name('notifications.logs');
        Route::post('notifications/test', 'testNotification')->middleware('permission:settings.manage')->name('notifications.test');
        Route::middleware('permission:settings.manage')->group(function () {
            Route::post('devices', 'storeDevice')->name('devices.store');
            Route::put('devices/{device}', 'updateDevice')->name('devices.update');
            Route::post('devices/{device}/approve', 'approveDevice')->name('devices.approve');
            Route::post('devices/{device}/revoke', 'revokeDevice')->name('devices.revoke');
        });
        Route::get('{category}', 'show')->whereIn('category', $categories)->name('show');
        Route::middleware('permission:settings.manage')->group(function () use ($categories) {
            Route::put('{category}', 'update')->whereIn('category', $categories)->name('update');
            Route::post('{category}/publish', 'publish')->whereIn('category', ['branding', 'receipts'])->name('publish');
            Route::post('{category}/restore', 'restore')->whereIn('category', ['branding', 'receipts'])->name('restore');
        });
    });
});

Route::prefix('org')->name('org.')->middleware('permission:agencies.manage')->group(function () {
    Route::get('agencies/overview', [PressingAgencyController::class, 'agenciesOverview'])->name('agencies.overview');
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
