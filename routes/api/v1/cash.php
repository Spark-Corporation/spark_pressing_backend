<?php

use App\Http\Controllers\Api\V1\Cash\CashCategoryController;
use App\Http\Controllers\Api\V1\Cash\CashMovementController;
use App\Http\Controllers\Api\V1\Cash\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:cash.manage')->group(function () {
    Route::get('cash-movements', [CashMovementController::class, 'index'])->name('cash.index');
    Route::post('cash-movements', [CashMovementController::class, 'store'])->middleware('idempotent')->name('cash.store');
    Route::put('cash-movements/{cashMovement}', [CashMovementController::class, 'update'])->name('cash.update');
    Route::post('cash-movements/{cashMovement}/validate', [CashMovementController::class, 'validateMovement'])->name('cash.validate');
    Route::delete('cash-movements/{cashMovement}', [CashMovementController::class, 'destroy'])->name('cash.destroy');
    Route::get('cash-categories', [CashCategoryController::class, 'index'])->name('cash.categories.index');
    Route::post('cash-categories', [CashCategoryController::class, 'store'])->name('cash.categories.store');
});

Route::prefix('receipts')->name('receipts.')->middleware('permission:reports.view')->group(function () {
    Route::get('daily', [ReceiptController::class, 'daily'])->name('daily');
    Route::get('general', [ReceiptController::class, 'general'])->middleware('permission:reports.consolidated')->name('general');
    Route::get('close', [ReceiptController::class, 'close'])->name('close');
    Route::get('unpaid', [ReceiptController::class, 'unpaid'])->name('unpaid');
    Route::get('settled', [ReceiptController::class, 'settled'])->name('settled');
    Route::get('transactions', [ReceiptController::class, 'transactions'])->name('transactions');
});
