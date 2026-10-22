<?php

use App\Http\Controllers\Api\V1\Deposits\DepositController;
use App\Http\Controllers\Api\V1\Tenancy\TeamController;
use Illuminate\Support\Facades\Route;

// Liens signés envoyés aux clients (« Envoyer au client »).
Route::middleware('signed')->prefix('public')->name('public.')->group(function () {
    Route::get('deposits/{deposit}/document', [DepositController::class, 'publicDocument'])->name('deposits.document');
});

// Acceptation d’invitation équipe (Figma 11) — lien public 72 h.
Route::prefix('invitations')->name('invitations.')->controller(TeamController::class)->group(function () {
    Route::get('{token}', 'showInvitation')->middleware('throttle:30,1')->name('show');
    Route::post('{token}/accept', 'acceptInvitation')->middleware('throttle:10,1')->name('accept');
});
