<?php

use App\Http\Controllers\Api\V1\Cash\CashCategoryController;
use App\Http\Controllers\Api\V1\Cash\CashMovementController;
use App\Http\Controllers\Api\V1\Cash\CashSessionController;
use App\Http\Controllers\Api\V1\Cash\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware('permission:cash.manage')->group(function () {
    Route::get('cash-movements', [CashMovementController::class, 'index'])->name('cash.index');
    Route::post('cash-movements', [CashMovementController::class, 'store'])->middleware('idempotent')->name('cash.store');
    Route::get('cash-movements/{cashMovement}', [CashMovementController::class, 'show'])->name('cash.show');
    Route::put('cash-movements/{cashMovement}', [CashMovementController::class, 'update'])->name('cash.update');
    Route::post('cash-movements/{cashMovement}/validate', [CashMovementController::class, 'validateMovement'])->name('cash.validate');
    Route::delete('cash-movements/{cashMovement}', [CashMovementController::class, 'destroy'])->name('cash.destroy');
    Route::get('cash/center', [CashSessionController::class, 'center'])->name('cash.center');
    Route::get('cash/journal', [CashSessionController::class, 'journal'])->name('cash.journal');
    Route::get('cash/sessions/current', [CashSessionController::class, 'current'])->name('cash.sessions.current');
    Route::post('cash/sessions', [CashSessionController::class, 'open'])->name('cash.sessions.open');
    Route::get('cash/sessions/{cashSession}/closing', [CashSessionController::class, 'closing'])->name('cash.sessions.closing');
    Route::post('cash/sessions/{cashSession}/count', [CashSessionController::class, 'count'])->name('cash.sessions.count');
    Route::post('cash/sessions/{cashSession}/control', [CashSessionController::class, 'control'])->name('cash.sessions.control');
    Route::post('cash/sessions/{cashSession}/close', [CashSessionController::class, 'close'])->name('cash.sessions.close');
    Route::get('cash/sessions/{cashSession}/report', [CashSessionController::class, 'report'])->name('cash.sessions.report');
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
