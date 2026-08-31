<?php

use App\Http\Controllers\Loyalty\LoyaltyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/loyalty', [LoyaltyController::class, 'index'])
        ->name('portal.loyalty.index');
    Route::post('/portal/loyalty/redeem', [LoyaltyController::class, 'redeem'])
        ->middleware('throttle:6,1')
        ->name('portal.loyalty.redeem');
});
