<?php

use App\Http\Controllers\Api\V1\Pos\PosController;
use App\Http\Controllers\Api\V1\Reporting\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'agency'])->name('dashboard');
Route::get('pos/bootstrap', [PosController::class, 'bootstrap'])->name('pos.bootstrap');
Route::post('sync', [PosController::class, 'sync'])->middleware(['permission:deposits.create', 'idempotent'])->name('pos.sync');
