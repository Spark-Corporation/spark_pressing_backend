<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\StaffProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::post('staff/login', 'staffLogin')->middleware('throttle:login')->name('staff.login');
    Route::post('staff/password/channels', 'resetChannels')->middleware('throttle:login')->name('staff.reset.channels');
    Route::post('staff/forgot-password', 'forgotPassword')->middleware('throttle:login')->name('staff.forgot');
    Route::post('staff/verify-reset-code', 'verifyResetCode')->middleware('throttle:login')->name('staff.reset.verify');
    Route::post('staff/reset-password', 'resetPassword')->middleware('throttle:login')->name('staff.reset');
    Route::post('client/login', 'clientLogin')->middleware('throttle:login')->name('client.login');
    Route::post('client/register', 'clientRegister')->middleware('throttle:login')->name('client.register');
    Route::post('superadmin/login', 'adminLogin')->middleware('throttle:login')->name('superadmin.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', 'me')->name('me');
        Route::put('profile', 'updateProfile')->name('profile');
        Route::post('logout', 'logout')->name('logout');
    });
});

// « Profil et sécurité staff » (Figma 11) — compte courant.
Route::prefix('auth/staff')->name('auth.staff.')->middleware('auth:sanctum')->controller(StaffProfileController::class)->group(function () {
    Route::get('profile', 'show')->name('profile.show');
    Route::put('profile', 'update')->name('profile.update');
    Route::put('password', 'password')->name('password');
    Route::delete('sessions/{tokenId}', 'revokeSession')->whereNumber('tokenId')->name('sessions.revoke');
    Route::post('sessions/revoke-others', 'revokeOthers')->name('sessions.revoke-others');
});
