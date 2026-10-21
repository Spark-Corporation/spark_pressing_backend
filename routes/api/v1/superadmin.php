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

    Route::get('licenses/overview', [LicenseController::class, 'overview'])->name('licenses.overview');
    Route::get('licenses/export', [LicenseController::class, 'export'])->name('licenses.export');
    Route::get('licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::post('licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::get('licenses/{license}', [LicenseController::class, 'show'])->name('licenses.show');
    Route::put('licenses/{license}', [LicenseController::class, 'update'])->name('licenses.update');
    Route::post('licenses/{license}/activate', [LicenseController::class, 'activate'])->name('licenses.activate');
    Route::post('licenses/{license}/renew', [LicenseController::class, 'renew'])->name('licenses.renew');
    Route::post('licenses/{license}/suspend', [LicenseController::class, 'suspend'])->name('licenses.suspend');
    Route::post('licenses/{license}/reactivate', [LicenseController::class, 'reactivate'])->name('licenses.reactivate');
    Route::get('licenses/{license}/payments', [LicenseController::class, 'payments'])->name('licenses.payments.index');
    Route::post('licenses/{license}/payments/{payment}/validate', [LicenseController::class, 'validatePayment'])->name('licenses.payments.validate');
    Route::get('licenses/{license}/payments/{payment}/invoice', [LicenseController::class, 'invoice'])->name('licenses.payments.invoice');
    Route::delete('licenses/{license}', [LicenseController::class, 'destroy'])->name('licenses.destroy');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
    Route::put('roles/{role}', [RoleController::class, 'sync'])->name('roles.sync');
});
