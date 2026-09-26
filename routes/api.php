<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    require __DIR__.'/api/v1/health.php';
    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/superadmin.php';
    require __DIR__.'/api/v1/portal.php';

    Route::middleware('staff')->group(function () {
        require __DIR__.'/api/v1/pos.php';
        require __DIR__.'/api/v1/clients.php';
        require __DIR__.'/api/v1/catalog.php';
        require __DIR__.'/api/v1/deposits.php';
        require __DIR__.'/api/v1/cash.php';
        require __DIR__.'/api/v1/reports.php';
        require __DIR__.'/api/v1/organization.php';
    });
});
