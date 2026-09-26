<?php

use App\Http\Controllers\Api\V1\Tenancy\AgencyController;
use App\Http\Controllers\Api\V1\Tenancy\LicenseController;
use App\Http\Controllers\Api\V1\Tenancy\PressingController;
use App\Http\Controllers\Api\V1\Tenancy\RoleController;
use App\Http\Controllers\Api\V1\Tenancy\StaffUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin')->prefix('superadmin')->name('superadmin.')->group(function () {
    Route::apiResource('pressings', PressingController::class);
    Route::apiResource('agencies', AgencyController::class);

    Route::get('users', [StaffUserController::class, 'index'])->name('users.index');
    Route::post('users', [StaffUserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [StaffUserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [StaffUserController::class, 'destroy'])->name('users.destroy');

    Route::get('licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::post('licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::put('licenses/{license}', [LicenseController::class, 'update'])->name('licenses.update');
    Route::post('licenses/{license}/activate', [LicenseController::class, 'activate'])->name('licenses.activate');
    Route::delete('licenses/{license}', [LicenseController::class, 'destroy'])->name('licenses.destroy');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
    Route::put('roles/{role}', [RoleController::class, 'sync'])->name('roles.sync');
});
