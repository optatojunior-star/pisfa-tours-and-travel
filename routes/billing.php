<?php

use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\QuotationController as AdminQuotationController;
use App\Http\Controllers\Admin\QuotationRequestController as AdminQuotationRequestController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\QuotationController;
use App\Http\Controllers\Billing\QuotationRequestController;
use Illuminate\Support\Facades\Route;

// The public form at GET /request-quotation now posts somewhere real.
Route::post('/request-quotation', [QuotationRequestController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('quotation-requests.store');

/*
 * Guest tracking by unguessable token.
 *
 * Throttled because the token is the only credential: a brute-force attempt
 * must be expensive as well as futile against 32 bytes of entropy.
 */
Route::get('/track/quotation-request/{token}', [QuotationRequestController::class, 'track'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:30,1')
    ->name('quotation-requests.track');

Route::get('/track/quotation/{token}', [QuotationController::class, 'track'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:30,1')
    ->name('quotations.track');

Route::post('/track/quotation/{token}/respond', [QuotationController::class, 'respondAsGuest'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:10,1')
    ->name('quotations.track.respond');

Route::get('/track/invoice/{token}', [InvoiceController::class, 'track'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:30,1')
    ->name('invoices.track');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/quotation-requests', [QuotationRequestController::class, 'index'])
        ->name('portal.quotation-requests.index');
    Route::get('/portal/quotation-requests/{customerQuotationRequest}', [QuotationRequestController::class, 'show'])
        ->name('portal.quotation-requests.show');

    Route::get('/portal/quotations', [QuotationController::class, 'index'])
        ->name('portal.quotations.index');
    Route::get('/portal/quotations/{customerQuotation}', [QuotationController::class, 'show'])
        ->name('portal.quotations.show');
    Route::post('/portal/quotations/{customerQuotation}/respond', [QuotationController::class, 'respond'])
        ->middleware('throttle:10,1')
        ->name('portal.quotations.respond');

    Route::get('/portal/invoices', [InvoiceController::class, 'index'])
        ->name('portal.invoices.index');
    Route::get('/portal/invoices/{customerInvoice}', [InvoiceController::class, 'show'])
        ->name('portal.invoices.show');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/quotation-requests', [AdminQuotationRequestController::class, 'index'])
            ->name('quotation-requests.index');
        Route::get('/quotation-requests/{quotationRequest}', [AdminQuotationRequestController::class, 'show'])
            ->name('quotation-requests.show');
        Route::patch('/quotation-requests/{quotationRequest}', [AdminQuotationRequestController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('quotation-requests.update');

        // "new" is declared before "{quotation}" so the literal path is not
        // swallowed by the wildcard.
        Route::get('/quotations/new', [AdminQuotationController::class, 'create'])
            ->name('quotations.create');
        Route::get('/quotations', [AdminQuotationController::class, 'index'])
            ->name('quotations.index');
        Route::post('/quotations', [AdminQuotationController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('quotations.store');
        Route::get('/quotations/{quotation}', [AdminQuotationController::class, 'show'])
            ->name('quotations.show');
        Route::get('/quotations/{quotation}/edit', [AdminQuotationController::class, 'edit'])
            ->name('quotations.edit');
        Route::patch('/quotations/{quotation}', [AdminQuotationController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('quotations.update');
        Route::post('/quotations/{quotation}/send', [AdminQuotationController::class, 'send'])
            ->middleware('throttle:20,1')
            ->name('quotations.send');
        Route::post('/quotations/{quotation}/revise', [AdminQuotationController::class, 'revise'])
            ->middleware('throttle:20,1')
            ->name('quotations.revise');
        Route::post('/quotations/{quotation}/cancel', [AdminQuotationController::class, 'cancel'])
            ->middleware('throttle:20,1')
            ->name('quotations.cancel');
        Route::post('/quotations/{quotation}/convert', [AdminQuotationController::class, 'convert'])
            ->middleware('throttle:20,1')
            ->name('quotations.convert');

        Route::get('/invoices', [AdminInvoiceController::class, 'index'])
            ->name('invoices.index');
        Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show'])
            ->name('invoices.show');
        Route::post('/invoices/{invoice}/issue', [AdminInvoiceController::class, 'issue'])
            ->middleware('throttle:20,1')
            ->name('invoices.issue');
        Route::post('/invoices/{invoice}/cancel', [AdminInvoiceController::class, 'cancel'])
            ->middleware('throttle:20,1')
            ->name('invoices.cancel');
        Route::post('/invoices/{invoice}/void', [AdminInvoiceController::class, 'void'])
            ->middleware('throttle:20,1')
            ->name('invoices.void');
    });
