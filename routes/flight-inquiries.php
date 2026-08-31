<?php

use App\Http\Controllers\Admin\FlightInquiryController as AdminFlightInquiryController;
use App\Http\Controllers\FlightInquiries\FlightInquiryController;
use App\Http\Controllers\FlightInquiries\FlightInquiryPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/flights', [FlightInquiryController::class, 'create'])->name('flight-inquiries.create');
Route::get('/flights/{scope}', [FlightInquiryController::class, 'create'])
    ->where('scope', 'domestic|international')
    ->name('flight-inquiries.create.scope');

Route::post('/flights/enquiries', [FlightInquiryController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('flight-inquiries.store');

// A guest enquiry has no account to authorize against, so its tracking page is
// reachable only through the temporary signed link sent by email.
Route::get('/flights/enquiries/{flightInquiry}', [FlightInquiryController::class, 'guest'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('flight-inquiries.guest.show');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/flights', [FlightInquiryPortalController::class, 'index'])
        ->name('portal.flight-inquiries.index');
    Route::get('/portal/flights/{customerFlightInquiry}', [FlightInquiryPortalController::class, 'show'])
        ->name('portal.flight-inquiries.show');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/flight-inquiries', [AdminFlightInquiryController::class, 'index'])
            ->name('flight-inquiries.index');
        Route::get('/flight-inquiries/{flightInquiry}', [AdminFlightInquiryController::class, 'show'])
            ->name('flight-inquiries.show');
        Route::patch('/flight-inquiries/{flightInquiry}/transition', [AdminFlightInquiryController::class, 'transition'])
            ->name('flight-inquiries.transition');
        Route::patch('/flight-inquiries/{flightInquiry}/assignment', [AdminFlightInquiryController::class, 'assign'])
            ->name('flight-inquiries.assignment');
        Route::post('/flight-inquiries/{flightInquiry}/entries', [AdminFlightInquiryController::class, 'storeEntry'])
            ->middleware('throttle:30,1')
            ->name('flight-inquiries.entries.store');
    });
