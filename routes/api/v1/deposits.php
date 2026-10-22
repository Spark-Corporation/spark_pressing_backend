<?php

use App\Http\Controllers\Api\V1\Deposits\DepositController;
use App\Http\Controllers\Api\V1\Deposits\QrLookupController;
use App\Http\Controllers\Api\V1\Deposits\RetrievalController;
use Illuminate\Support\Facades\Route;

Route::controller(DepositController::class)->prefix('deposits')->name('deposits.')->group(function () {
    Route::get('due', 'due')->middleware('permission:deposits.view')->name('due');
    Route::get('retrieved', 'retrieved')->middleware('permission:deposits.retrieve|deposits.view')->name('retrieved');
    Route::get('ready', 'ready')->middleware('permission:deposits.retrieve')->name('ready');
    Route::get('workshop', 'workshopBoard')->middleware('permission:deposits.view|workshop.transition')->name('workshop.board');
    Route::get('qr/{token}', QrLookupController::class)->middleware('permission:deposits.view|workshop.transition')->name('qr');
    Route::get('export', 'export')->middleware('permission:deposits.view')->name('export');
    Route::get('/', 'index')->middleware('permission:deposits.view')->name('index');
    Route::post('/', 'store')->middleware(['permission:deposits.create', 'idempotent'])->name('store');
    Route::get('{deposit}', 'show')->middleware('permission:deposits.view')->name('show');
    Route::post('{deposit}/payments', 'pay')->middleware(['permission:deposits.pay', 'idempotent'])->name('pay');
    Route::post('{deposit}/retrieve', 'retrieve')->middleware(['permission:deposits.retrieve', 'idempotent'])->name('retrieve');
    Route::post('{deposit}/retrieve-partial', 'retrievePartial')->middleware(['permission:deposits.retrieve', 'idempotent'])->name('retrieve-partial');
    Route::get('{deposit}/workshop', 'workshopPreview')->middleware('permission:deposits.view|workshop.transition')->name('workshop.preview');
    Route::post('{deposit}/workshop', 'transition')->middleware('permission:workshop.transition')->name('workshop');
    Route::get('{deposit}/document', 'document')->middleware('permission:deposits.view')->name('document');
    Route::post('{deposit}/document/send', 'sendDocument')->middleware('permission:deposits.view')->name('document.send');
    Route::delete('{deposit}', 'destroy')->middleware('permission:deposits.create')->name('destroy');
});

Route::controller(RetrievalController::class)->name('retrievals.')->group(function () {
    Route::get('retrievals/center', 'center')->middleware('permission:deposits.retrieve|deposits.view')->name('center');
    Route::get('retrievals/aged', 'aged')->middleware('permission:deposits.retrieve|deposits.view')->name('aged');
    Route::post('deposits/{deposit}/aged-action', 'agedAction')->middleware('permission:deposits.retrieve')->name('aged-action');
    Route::post('deposits/{deposit}/notify', 'notify')->middleware('permission:deposits.retrieve|deposits.view')->name('notify');
    Route::post('deposits/{deposit}/notification-read', 'notificationRead')->middleware('permission:deposits.retrieve|deposits.view')->name('notification-read');
    Route::get('deposits/{deposit}/checkout', 'showCheckout')->middleware('permission:deposits.retrieve')->name('checkout.show');
    Route::post('deposits/{deposit}/checkout', 'checkout')->middleware(['permission:deposits.retrieve', 'idempotent'])->name('checkout');
});
