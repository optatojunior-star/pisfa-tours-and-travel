<?php

use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\PaymentWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/checkout/{type}/{reference}', [CheckoutController::class, 'show'])
        ->name('payments.checkout');
    Route::post('/checkout/{type}/{reference}', [CheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('payments.checkout.store');
    Route::get('/payments/{payment}/status', [CheckoutController::class, 'status'])
        ->name('payments.status');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])->name('payments.show');
        Route::post('/payments/{payment}/record', [AdminPaymentController::class, 'record'])
            ->middleware('throttle:20,1')
            ->name('payments.record');
        Route::post('/payments/{payment}/refund', [AdminPaymentController::class, 'refund'])
            ->middleware('throttle:20,1')
            ->name('payments.refund');
    });

// Provider notifications. Necessarily unauthenticated and CSRF-exempt, so
// integrity rests on signature verification, event-id deduplication, and the
// amount/currency checks in the settlement action.
Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.payments');
