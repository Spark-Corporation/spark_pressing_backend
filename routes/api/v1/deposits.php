<?php

use App\Http\Controllers\Api\V1\Deposits\DepositController;
use App\Http\Controllers\Api\V1\Deposits\QrLookupController;
use Illuminate\Support\Facades\Route;

Route::controller(DepositController::class)->prefix('deposits')->name('deposits.')->group(function () {
    Route::get('due', 'due')->middleware('permission:deposits.view')->name('due');
    Route::get('retrieved', 'retrieved')->middleware('permission:deposits.retrieve|deposits.view')->name('retrieved');
    Route::get('ready', 'ready')->middleware('permission:deposits.retrieve')->name('ready');
    Route::get('qr/{token}', QrLookupController::class)->middleware('permission:deposits.view|workshop.transition')->name('qr');
    Route::get('/', 'index')->middleware('permission:deposits.view')->name('index');
    Route::post('/', 'store')->middleware(['permission:deposits.create', 'idempotent'])->name('store');
    Route::get('{deposit}', 'show')->middleware('permission:deposits.view')->name('show');
    Route::post('{deposit}/payments', 'pay')->middleware(['permission:deposits.pay', 'idempotent'])->name('pay');
    Route::post('{deposit}/retrieve', 'retrieve')->middleware(['permission:deposits.retrieve', 'idempotent'])->name('retrieve');
    Route::post('{deposit}/retrieve-partial', 'retrievePartial')->middleware(['permission:deposits.retrieve', 'idempotent'])->name('retrieve-partial');
    Route::post('{deposit}/workshop', 'transition')->middleware('permission:workshop.transition')->name('workshop');
    Route::get('{deposit}/document', 'document')->middleware('permission:deposits.view')->name('document');
    Route::delete('{deposit}', 'destroy')->middleware('permission:deposits.create')->name('destroy');
});
