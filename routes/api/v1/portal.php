<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Portal\ClientPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware('client')->prefix('client')->name('portal.')->group(function () {
    Route::get('profile', [ClientPortalController::class, 'profile'])->name('profile');
    Route::put('profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('deposits', [ClientPortalController::class, 'deposits'])->name('deposits');
    Route::get('retrieves', [ClientPortalController::class, 'retrieves'])->name('retrieves');
    Route::get('deposits/{deposit}', [ClientPortalController::class, 'show'])->name('deposits.show');
    Route::get('loyalty', [ClientPortalController::class, 'loyalty'])->name('loyalty');
});
