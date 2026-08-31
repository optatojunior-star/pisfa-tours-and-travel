<?php

use App\Http\Controllers\Documents\DocumentDownloadController;
use Illuminate\Support\Facades\Route;

// Authorized download. DocumentPolicy delegates to the owning record's policy,
// so a customer reaches their own contract and nobody else's.
Route::get('/documents/{document}', [DocumentDownloadController::class, 'show'])
    ->middleware(['auth', 'verified', 'throttle:60,1'])
    ->name('documents.show');

// Short-lived signed link for a recipient with no account (for example a guest
// quotation). The signature is the authorization, so the expiry is short and
// the route is throttled.
Route::get('/documents/{document}/signed', [DocumentDownloadController::class, 'signed'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('documents.signed');
