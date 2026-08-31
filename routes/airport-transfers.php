<?php

use App\Http\Controllers\Admin\AirportTransferBookingController as AdminAirportTransferBookingController;
use App\Http\Controllers\Admin\AirportTransferSettingController;
use App\Http\Controllers\AirportTransfers\AirportTransferPlannerController;
use App\Http\Controllers\AirportTransfers\AirportTransferPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/airport-transfers', [AirportTransferPlannerController::class, 'index'])
    ->name('airport-transfers.index');

Route::post('/airport-transfers/requests', [AirportTransferPlannerController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('airport-transfer-bookings.store');

// Guests have no account to authorize against, so the acknowledgement page is
// reachable only through a temporary signed link mailed to the requester.
Route::get('/airport-transfers/acknowledgement/{airportTransferBooking}', [AirportTransferPlannerController::class, 'guest'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('airport-transfer-bookings.guest.show');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/airport-transfers', [AirportTransferPortalController::class, 'index'])
        ->name('portal.airport-transfer-bookings.index');
    Route::get('/portal/airport-transfers/{customerAirportTransferBooking}', [AirportTransferPortalController::class, 'show'])
        ->name('portal.airport-transfer-bookings.show');
    Route::patch('/portal/airport-transfers/{customerAirportTransferBooking}/cancel', [AirportTransferPortalController::class, 'cancel'])
        ->middleware('throttle:6,1')
        ->name('portal.airport-transfer-bookings.cancel');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/airport-transfer-bookings', [AdminAirportTransferBookingController::class, 'index'])
            ->name('airport-transfer-bookings.index');
        Route::get('/airport-transfer-bookings/{airportTransferBooking}', [AdminAirportTransferBookingController::class, 'show'])
            ->name('airport-transfer-bookings.show');
        Route::patch('/airport-transfer-bookings/{airportTransferBooking}/transition', [AdminAirportTransferBookingController::class, 'transition'])
            ->name('airport-transfer-bookings.transition');
        Route::patch('/airport-transfer-bookings/{airportTransferBooking}/assignment', [AdminAirportTransferBookingController::class, 'assign'])
            ->name('airport-transfer-bookings.assignment');
        Route::patch('/airport-transfer-bookings/{airportTransferBooking}/reschedule', [AdminAirportTransferBookingController::class, 'reschedule'])
            ->name('airport-transfer-bookings.reschedule');

        Route::get('/airport-transfer-settings', [AirportTransferSettingController::class, 'index'])
            ->name('airport-transfer-settings.index');
        Route::post('/airport-transfer-settings/airports', [AirportTransferSettingController::class, 'storeAirport'])
            ->name('airport-transfer-settings.airports.store');
        Route::patch('/airport-transfer-settings/airports/{airport}', [AirportTransferSettingController::class, 'updateAirport'])
            ->name('airport-transfer-settings.airports.update');
        Route::patch('/airport-transfer-settings/airports/{airport}/status', [AirportTransferSettingController::class, 'airportStatus'])
            ->name('airport-transfer-settings.airports.status');
        Route::post('/airport-transfer-settings/locations', [AirportTransferSettingController::class, 'storeLocation'])
            ->name('airport-transfer-settings.locations.store');
        Route::patch('/airport-transfer-settings/locations/{airportTransferLocation}', [AirportTransferSettingController::class, 'updateLocation'])
            ->name('airport-transfer-settings.locations.update');
        Route::patch('/airport-transfer-settings/locations/{airportTransferLocation}/status', [AirportTransferSettingController::class, 'locationStatus'])
            ->name('airport-transfer-settings.locations.status');
        Route::post('/airport-transfer-settings/rates', [AirportTransferSettingController::class, 'storeRate'])
            ->name('airport-transfer-settings.rates.store');
        Route::patch('/airport-transfer-settings/rates/{airportTransferRate}/status', [AirportTransferSettingController::class, 'rateStatus'])
            ->name('airport-transfer-settings.rates.status');
    });
