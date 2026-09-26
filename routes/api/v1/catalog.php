<?php

use App\Http\Controllers\Api\V1\Catalog\ArticleController;
use App\Http\Controllers\Api\V1\Catalog\ArticleImportController;
use App\Http\Controllers\Api\V1\Catalog\CodeSuffixController;
use App\Http\Controllers\Api\V1\Catalog\DeliveryHourController;
use App\Http\Controllers\Api\V1\Catalog\LaundryStatusController;
use App\Http\Controllers\Api\V1\Catalog\LoyalGroupController;
use App\Http\Controllers\Api\V1\Catalog\PromoController;
use App\Http\Controllers\Api\V1\Catalog\PromoSpecialController;
use App\Http\Controllers\Api\V1\Catalog\RenderController;
use Illuminate\Support\Facades\Route;

Route::prefix('articles')->name('articles.')->group(function () {
    Route::get('export', [ArticleImportController::class, 'export'])->middleware('permission:articles.manage')->name('export');
    Route::get('template', [ArticleImportController::class, 'template'])->middleware('permission:articles.manage')->name('template');
    Route::post('import', [ArticleImportController::class, 'import'])->middleware('permission:articles.manage')->name('import');
    Route::get('/', [ArticleController::class, 'index'])->name('index');
    Route::post('/', [ArticleController::class, 'store'])->middleware('permission:articles.manage')->name('store');
    Route::get('{article}', [ArticleController::class, 'show'])->name('show');
    Route::put('{article}', [ArticleController::class, 'update'])->middleware('permission:articles.manage')->name('update');
    Route::delete('{article}', [ArticleController::class, 'destroy'])->middleware('permission:articles.manage')->name('destroy');
});

Route::prefix('laundry-statuses')->name('laundry-statuses.')->group(function () {
    Route::get('/', [LaundryStatusController::class, 'index'])->name('index');
    Route::post('/', [LaundryStatusController::class, 'store'])->middleware('permission:articles.manage')->name('store');
    Route::put('{id}', [LaundryStatusController::class, 'update'])->middleware('permission:articles.manage')->name('update');
    Route::delete('{id}', [LaundryStatusController::class, 'destroy'])->middleware('permission:articles.manage')->name('destroy');
});

Route::prefix('renders')->name('renders.')->group(function () {
    Route::get('/', [RenderController::class, 'index'])->name('index');
    Route::post('/', [RenderController::class, 'store'])->middleware('permission:articles.manage')->name('store');
    Route::put('{id}', [RenderController::class, 'update'])->middleware('permission:articles.manage')->name('update');
    Route::delete('{id}', [RenderController::class, 'destroy'])->middleware('permission:articles.manage')->name('destroy');
});

Route::prefix('promos')->name('promos.')->group(function () {
    Route::get('/', [PromoController::class, 'index'])->name('index');
    Route::post('/', [PromoController::class, 'store'])->middleware('permission:settings.manage')->name('store');
    Route::put('{id}', [PromoController::class, 'update'])->middleware('permission:settings.manage')->name('update');
    Route::delete('{id}', [PromoController::class, 'destroy'])->middleware('permission:settings.manage')->name('destroy');
});

Route::prefix('promo-specials')->name('promo-specials.')->group(function () {
    Route::get('/', [PromoSpecialController::class, 'index'])->name('index');
    Route::post('/', [PromoSpecialController::class, 'store'])->middleware('permission:settings.manage')->name('store');
    Route::put('{id}', [PromoSpecialController::class, 'update'])->middleware('permission:settings.manage')->name('update');
    Route::delete('{id}', [PromoSpecialController::class, 'destroy'])->middleware('permission:settings.manage')->name('destroy');
});

Route::prefix('delivery-hours')->name('delivery-hours.')->group(function () {
    Route::get('/', [DeliveryHourController::class, 'index'])->name('index');
    Route::post('/', [DeliveryHourController::class, 'store'])->middleware('permission:settings.manage')->name('store');
    Route::put('{id}', [DeliveryHourController::class, 'update'])->middleware('permission:settings.manage')->name('update');
    Route::delete('{id}', [DeliveryHourController::class, 'destroy'])->middleware('permission:settings.manage')->name('destroy');
});

Route::prefix('code-suffixes')->name('code-suffixes.')->group(function () {
    Route::get('/', [CodeSuffixController::class, 'index'])->name('index');
    Route::post('/', [CodeSuffixController::class, 'store'])->middleware('permission:settings.manage')->name('store');
    Route::put('{id}', [CodeSuffixController::class, 'update'])->middleware('permission:settings.manage')->name('update');
    Route::delete('{id}', [CodeSuffixController::class, 'destroy'])->middleware('permission:settings.manage')->name('destroy');
});

Route::prefix('loyal-groups')->name('loyal-groups.')->group(function () {
    Route::get('/', [LoyalGroupController::class, 'index'])->name('index');
    Route::post('/', [LoyalGroupController::class, 'store'])->middleware('permission:settings.manage')->name('store');
    Route::put('{loyalGroup}', [LoyalGroupController::class, 'update'])->middleware('permission:settings.manage')->name('update');
    Route::get('{loyalGroup}/clients', [LoyalGroupController::class, 'clients'])->middleware('permission:settings.manage')->name('clients');
    Route::post('{loyalGroup}/clients', [LoyalGroupController::class, 'attach'])->middleware('permission:settings.manage')->name('clients.attach');
    Route::delete('{loyalGroup}/clients', [LoyalGroupController::class, 'detach'])->middleware('permission:settings.manage')->name('clients.detach');
    Route::delete('{loyalGroup}', [LoyalGroupController::class, 'destroy'])->middleware('permission:settings.manage')->name('destroy');
});
