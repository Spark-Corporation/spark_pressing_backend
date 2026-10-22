<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Portal\ClientAppController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware('client')->prefix('client')->name('portal.')->group(function () {
    Route::get('profile', [ClientPortalController::class, 'profile'])->name('profile');
    Route::put('profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('deposits', [ClientPortalController::class, 'deposits'])->name('deposits');
    Route::get('retrieves', [ClientPortalController::class, 'retrieves'])->name('retrieves');
    Route::get('deposits/{deposit}', [ClientPortalController::class, 'show'])->name('deposits.show');
    Route::get('loyalty', [ClientPortalController::class, 'loyalty'])->name('loyalty');

    // Application mobile client (Figma 14).
    Route::prefix('app')->name('app.')->controller(ClientAppController::class)->group(function () {
        Route::get('home', 'home')->name('home');
        Route::get('deposits', 'deposits')->name('deposits');
        Route::get('deposits/{deposit}', 'deposit')->whereNumber('deposit')->name('deposits.show');
        Route::get('loyalty', 'loyalty')->name('loyalty');
        Route::get('profile', 'profile')->name('profile');
        Route::put('profile', 'updateProfile')->name('profile.update');
        Route::put('password', 'password')->name('password');
        Route::post('sessions/revoke-others', 'revokeOtherSessions')->name('sessions.revoke-others');
        Route::get('data-export', 'export')->name('data-export');
        Route::post('account/delete', 'deleteAccount')->name('account.delete');
    });
});
