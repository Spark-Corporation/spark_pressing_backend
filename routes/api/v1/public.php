<?php

use App\Http\Controllers\Api\V1\Deposits\DepositController;
use Illuminate\Support\Facades\Route;

// Liens signés envoyés aux clients (« Envoyer au client »).
Route::middleware('signed')->prefix('public')->name('public.')->group(function () {
    Route::get('deposits/{deposit}/document', [DepositController::class, 'publicDocument'])->name('deposits.document');
});
