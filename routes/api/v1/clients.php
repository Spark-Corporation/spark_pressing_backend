<?php

use App\Http\Controllers\Api\V1\Crm\ClientController;
use App\Http\Controllers\Api\V1\Crm\LoyaltyController;
use App\Http\Controllers\Api\V1\Crm\WalletController;
use Illuminate\Support\Facades\Route;

Route::name('clients.')->group(function () {
    Route::get('clients/inactive', [ClientController::class, 'inactive'])->middleware('permission:clients.manage')->name('inactive');
    Route::get('clients', [ClientController::class, 'index'])->middleware('permission:clients.manage|deposits.view')->name('index');
    Route::post('clients', [ClientController::class, 'store'])->middleware('permission:clients.manage')->name('store');
    Route::get('clients/{client}', [ClientController::class, 'show'])->middleware('permission:clients.manage|deposits.view')->name('show');
    Route::get('clients/{client}/state', [ClientController::class, 'state'])->middleware('permission:clients.manage|reports.view')->name('state');
    Route::get('clients/{client}/referrals', [ClientController::class, 'referrals'])->middleware('permission:clients.manage')->name('referrals');
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware('permission:clients.manage')->name('update');
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware('permission:clients.manage')->name('destroy');
    Route::get('clients/{client}/loyalty', [LoyaltyController::class, 'show'])->middleware('permission:clients.manage|deposits.view')->name('loyalty');
    Route::get('clients/{client}/wallet', [WalletController::class, 'show'])->middleware('permission:clients.manage')->name('wallet');
    Route::post('clients/{client}/wallet/credit', [WalletController::class, 'credit'])->middleware('permission:clients.manage')->name('wallet.credit');
    Route::post('clients/{client}/wallet/debit', [WalletController::class, 'debit'])->middleware('permission:clients.manage')->name('wallet.debit');
});
