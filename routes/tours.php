<?php

use App\Http\Controllers\Admin\TourBookingController as AdminTourBookingController;
use App\Http\Controllers\Admin\TourCategoryController;
use App\Http\Controllers\Admin\TourDepartureController;
use App\Http\Controllers\Admin\TourPackageController;
use App\Http\Controllers\Tours\BookingPortalController;
use App\Http\Controllers\Tours\TourBookingController;
use App\Http\Controllers\Tours\TourCatalogueController;
use Illuminate\Support\Facades\Route;

Route::get('/tours', [TourCatalogueController::class, 'index'])->name('tours.index');
Route::get('/tours/{tourPackage}', [TourCatalogueController::class, 'show'])
    ->where('tourPackage', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('tours.show');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/tours/{tourPackage}/book', [TourBookingController::class, 'create'])
        ->where('tourPackage', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('tour-bookings.create');
    Route::post('/tours/{tourPackage}/bookings', [TourBookingController::class, 'store'])
        ->where('tourPackage', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware('throttle:10,1')
        ->name('tour-bookings.store');

    Route::get('/portal/bookings', [BookingPortalController::class, 'index'])
        ->name('portal.bookings.index');
    Route::get('/portal/bookings/{customerTourBooking}', [BookingPortalController::class, 'show'])
        ->name('portal.bookings.show');
    Route::patch('/portal/bookings/{customerTourBooking}/cancel', [BookingPortalController::class, 'cancel'])
        ->middleware('throttle:6,1')
        ->name('portal.bookings.cancel');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/tour-categories', [TourCategoryController::class, 'index'])->name('tour-categories.index');
        Route::post('/tour-categories', [TourCategoryController::class, 'store'])->name('tour-categories.store');
        Route::patch('/tour-categories/{tourCategory}', [TourCategoryController::class, 'update'])->name('tour-categories.update');
        Route::patch('/tour-categories/{tourCategory}/status', [TourCategoryController::class, 'toggle'])->name('tour-categories.toggle');

        Route::get('/tours', [TourPackageController::class, 'index'])->name('tours.index');
        Route::get('/tours/create', [TourPackageController::class, 'create'])->name('tours.create');
        Route::post('/tours', [TourPackageController::class, 'store'])->name('tours.store');
        Route::get('/tours/{tourPackage}', [TourPackageController::class, 'show'])->name('tours.show');
        Route::get('/tours/{tourPackage}/edit', [TourPackageController::class, 'edit'])->name('tours.edit');
        Route::patch('/tours/{tourPackage}', [TourPackageController::class, 'update'])->name('tours.update');
        Route::patch('/tours/{tourPackage}/publish', [TourPackageController::class, 'publish'])->name('tours.publish');
        Route::patch('/tours/{tourPackage}/archive', [TourPackageController::class, 'archive'])->name('tours.archive');
        Route::patch('/tours/{tourPackage}/restore', [TourPackageController::class, 'restore'])->name('tours.restore');

        Route::post('/tours/{tourPackage}/departures', [TourDepartureController::class, 'store'])->name('tour-departures.store');
        Route::patch('/tours/{tourPackage}/departures/{tourDeparture}', [TourDepartureController::class, 'update'])->name('tour-departures.update');
        Route::patch('/tours/{tourPackage}/departures/{tourDeparture}/status', [TourDepartureController::class, 'status'])->name('tour-departures.status');

        Route::get('/tour-bookings', [AdminTourBookingController::class, 'index'])->name('tour-bookings.index');
        Route::get('/tour-bookings/{tourBooking}', [AdminTourBookingController::class, 'show'])->name('tour-bookings.show');
        Route::patch('/tour-bookings/{tourBooking}/transition', [AdminTourBookingController::class, 'transition'])->name('tour-bookings.transition');
        Route::patch('/tour-bookings/{tourBooking}/assignment', [AdminTourBookingController::class, 'assign'])->name('tour-bookings.assign');
    });
