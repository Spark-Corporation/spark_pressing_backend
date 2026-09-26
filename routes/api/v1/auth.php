<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    Route::post('staff/login', 'staffLogin')->middleware('throttle:login')->name('staff.login');
    Route::post('staff/forgot-password', 'forgotPassword')->middleware('throttle:login')->name('staff.forgot');
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
