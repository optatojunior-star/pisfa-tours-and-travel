<?php

use App\Http\Controllers\Accommodation\PropertyBookingController;
use App\Http\Controllers\Accommodation\PropertyCatalogueController;
use App\Http\Controllers\Admin\PropertyBookingController as AdminPropertyBookingController;
use App\Http\Controllers\Admin\PropertyController;
use Illuminate\Support\Facades\Route;

/*
 * The catalogue is public; booking a stay needs a verified customer account,
 * because a stay is a paid commitment against contended rooms rather than an
 * enquiry.
 */
Route::get('/accommodation', [PropertyCatalogueController::class, 'index'])->name('accommodation.index');

// Constrained here as well as validated on save, so a path that could never be
// a slug never reaches a query.
Route::get('/accommodation/{property}', [PropertyCatalogueController::class, 'show'])
    ->where('property', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('accommodation.show');

Route::get('/accommodation/{property}/rooms/{roomType}/availability', [PropertyCatalogueController::class, 'availability'])
    ->where('property', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:60,1')
    ->name('accommodation.availability');

Route::post('/accommodation/{property}/book', [PropertyBookingController::class, 'store'])
    ->where('property', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('accommodation.book');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/portal/stays/{customerPropertyBooking}', [PropertyBookingController::class, 'show'])
        ->name('portal.property-bookings.show');
    Route::post('/portal/stays/{customerPropertyBooking}/cancel', [PropertyBookingController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('portal.property-bookings.cancel');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        // Stays are declared before the {property} routes so that
        // /admin/accommodation/stays is never read as a property slug.
        Route::get('/accommodation/stays', [AdminPropertyBookingController::class, 'index'])
            ->name('accommodation.bookings.index');
        Route::get('/accommodation/stays/{booking}', [AdminPropertyBookingController::class, 'show'])
            ->name('accommodation.bookings.show');
        Route::post('/accommodation/stays/{booking}/confirm', [AdminPropertyBookingController::class, 'confirm'])
            ->middleware('throttle:60,1')
            ->name('accommodation.bookings.confirm');
        Route::post('/accommodation/stays/{booking}/decline', [AdminPropertyBookingController::class, 'decline'])
            ->middleware('throttle:60,1')
            ->name('accommodation.bookings.decline');
        Route::post('/accommodation/stays/{booking}/cancel', [AdminPropertyBookingController::class, 'cancel'])
            ->middleware('throttle:60,1')
            ->name('accommodation.bookings.cancel');
        Route::post('/accommodation/stays/{booking}/check-in', [AdminPropertyBookingController::class, 'checkIn'])
            ->middleware('throttle:60,1')
            ->name('accommodation.bookings.check-in');
        Route::post('/accommodation/stays/{booking}/check-out', [AdminPropertyBookingController::class, 'checkOut'])
            ->middleware('throttle:60,1')
            ->name('accommodation.bookings.check-out');

        Route::get('/accommodation/new', [PropertyController::class, 'create'])
            ->name('accommodation.create');
        Route::get('/accommodation', [PropertyController::class, 'index'])
            ->name('accommodation.index');
        Route::post('/accommodation', [PropertyController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('accommodation.store');

        Route::get('/accommodation/{property}', [PropertyController::class, 'show'])
            ->name('accommodation.show');
        Route::get('/accommodation/{property}/edit', [PropertyController::class, 'edit'])
            ->name('accommodation.edit');
        Route::patch('/accommodation/{property}', [PropertyController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('accommodation.update');

        Route::post('/accommodation/{property}/publish', [PropertyController::class, 'publish'])
            ->middleware('throttle:30,1')
            ->name('accommodation.publish');
        Route::post('/accommodation/{property}/unpublish', [PropertyController::class, 'unpublish'])
            ->middleware('throttle:30,1')
            ->name('accommodation.unpublish');
        Route::post('/accommodation/{property}/archive', [PropertyController::class, 'archive'])
            ->middleware('throttle:30,1')
            ->name('accommodation.archive');
        Route::post('/accommodation/{property}/restore', [PropertyController::class, 'restore'])
            ->middleware('throttle:30,1')
            ->name('accommodation.restore');

        Route::post('/accommodation/{property}/rooms', [PropertyController::class, 'storeRoomType'])
            ->middleware('throttle:30,1')
            ->name('accommodation.rooms.store');
        Route::patch('/accommodation/{property}/rooms/{roomType}', [PropertyController::class, 'updateRoomType'])
            ->middleware('throttle:30,1')
            ->name('accommodation.rooms.update');
        Route::post('/accommodation/{property}/rooms/{roomType}/rates', [PropertyController::class, 'storeRoomRate'])
            ->middleware('throttle:30,1')
            ->name('accommodation.rates.store');
        Route::post('/accommodation/{property}/rates/{roomRate}/retire', [PropertyController::class, 'deactivateRoomRate'])
            ->middleware('throttle:30,1')
            ->name('accommodation.rates.retire');
    });
