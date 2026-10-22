<?php

use App\Http\Controllers\Api\V1\Ops\AuditLogController;
use App\Http\Controllers\Api\V1\Settings\ExchangeRateController;
use App\Http\Controllers\Api\V1\Settings\PressingSettingsController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Sync\SyncController;
use App\Http\Controllers\Api\V1\Tenancy\PressingAgencyController;
use App\Http\Controllers\Api\V1\Tenancy\RoleController;
use App\Http\Controllers\Api\V1\Tenancy\TeamController;
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

// Figma « 11 — Équipe, profils & permissions ».
Route::prefix('team')->name('team.')->middleware('permission:roles.manage|agencies.manage')->group(function () {
    Route::get('overview', [TeamController::class, 'overview'])->name('overview');
    Route::get('users', [TeamController::class, 'users'])->name('users');
    Route::get('users/{user}', [TeamController::class, 'showUser'])->name('users.show');
    Route::put('users/{user}', [TeamController::class, 'updateUser'])->middleware('permission:roles.manage')->name('users.update');
    Route::post('users/{user}/disable', [TeamController::class, 'disableUser'])->middleware('permission:roles.manage')->name('users.disable');
    Route::post('users/{user}/enable', [TeamController::class, 'enableUser'])->middleware('permission:roles.manage')->name('users.enable');
    Route::get('users/{user}/sessions', [TeamController::class, 'sessions'])->name('users.sessions');
    Route::delete('users/{user}/sessions/{tokenId}', [TeamController::class, 'revokeSession'])->middleware('permission:roles.manage')->name('users.sessions.revoke');
    Route::get('activity', [TeamController::class, 'activity'])->name('activity');
    Route::get('invitations', [TeamController::class, 'invitations'])->name('invitations');
    Route::post('invitations', [TeamController::class, 'invite'])->middleware('permission:roles.manage')->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])->middleware('permission:roles.manage')->name('invitations.resend');
    Route::post('invitations/{invitation}/revoke', [TeamController::class, 'revokeInvitation'])->middleware('permission:roles.manage')->name('invitations.revoke');
});

Route::middleware('auth:sanctum')->prefix('team')->name('team.mfa.')->group(function () {
    Route::post('mfa/setup', [TeamController::class, 'setupMfa'])->name('setup');
    Route::post('mfa/confirm', [TeamController::class, 'confirmMfa'])->name('confirm');
    Route::post('mfa/disable', [TeamController::class, 'disableMfa'])->name('disable');
});

Route::middleware('permission:roles.manage')->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/catalog', [RoleController::class, 'catalog'])->name('roles.catalog');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
});

// Figma « 12 — Synchronisation ».
Route::prefix('sync')->name('sync.')->group(function () {
    Route::get('center', [SyncController::class, 'center'])->middleware('permission:reports.view|agencies.manage|roles.manage')->name('center');
    Route::get('batches/{batch}', [SyncController::class, 'showBatch'])->middleware('permission:reports.view|agencies.manage|roles.manage')->name('batches.show');
});

Route::middleware('permission:audit.view')->group(function () {
    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('print-logs', [AuditLogController::class, 'prints'])->name('prints.index');
});
