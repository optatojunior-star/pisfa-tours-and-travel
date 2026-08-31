<?php

use App\Http\Controllers\Admin\SalesEnquiryController;
use App\Http\Controllers\Admin\VehicleListingController;
use App\Http\Controllers\Sales\ShowroomController;
use Illuminate\Support\Facades\Route;

/*
 * The public showroom is open to everyone, including guests, who may enquire
 * about a car without an account. Everything under /admin is staff-only and
 * re-checks its policy in the controller.
 */
Route::get('/showroom', [ShowroomController::class, 'index'])->name('showroom.index');

// Constrained here as well as validated on save, so a path that could never be
// a slug never reaches a query.
Route::get('/showroom/{listing}', [ShowroomController::class, 'show'])
    ->where('listing', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('showroom.show');

Route::post('/showroom/{listing}/enquire', [ShowroomController::class, 'enquire'])
    ->where('listing', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:6,1')
    ->name('showroom.enquire');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/showroom/new', [VehicleListingController::class, 'create'])
            ->name('showroom.create');
        Route::get('/showroom', [VehicleListingController::class, 'index'])
            ->name('showroom.index');
        Route::post('/showroom', [VehicleListingController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('showroom.store');

        // Enquiries are declared before the {listing} routes so that
        // /admin/showroom/enquiries is never read as a listing slug.
        Route::get('/showroom/enquiries', [SalesEnquiryController::class, 'index'])
            ->name('showroom.enquiries.index');
        Route::get('/showroom/enquiries/{enquiry}', [SalesEnquiryController::class, 'show'])
            ->name('showroom.enquiries.show');
        Route::post('/showroom/enquiries/{enquiry}/advance', [SalesEnquiryController::class, 'advance'])
            ->middleware('throttle:60,1')
            ->name('showroom.enquiries.advance');
        Route::post('/showroom/enquiries/{enquiry}/assign', [SalesEnquiryController::class, 'assign'])
            ->middleware('throttle:60,1')
            ->name('showroom.enquiries.assign');
        Route::post('/showroom/enquiries/{enquiry}/note', [SalesEnquiryController::class, 'note'])
            ->middleware('throttle:60,1')
            ->name('showroom.enquiries.note');

        Route::get('/showroom/{listing}', [VehicleListingController::class, 'show'])
            ->name('showroom.show');
        Route::get('/showroom/{listing}/edit', [VehicleListingController::class, 'edit'])
            ->name('showroom.edit');
        Route::patch('/showroom/{listing}', [VehicleListingController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('showroom.update');
        Route::post('/showroom/{listing}/publish', [VehicleListingController::class, 'publish'])
            ->middleware('throttle:30,1')
            ->name('showroom.publish');
        Route::post('/showroom/{listing}/reserve', [VehicleListingController::class, 'reserve'])
            ->middleware('throttle:30,1')
            ->name('showroom.reserve');
        Route::post('/showroom/{listing}/release', [VehicleListingController::class, 'release'])
            ->middleware('throttle:30,1')
            ->name('showroom.release');
        Route::post('/showroom/{listing}/restore', [VehicleListingController::class, 'restore'])
            ->middleware('throttle:30,1')
            ->name('showroom.restore');
        Route::post('/showroom/{listing}/withdraw', [VehicleListingController::class, 'withdraw'])
            ->middleware('throttle:30,1')
            ->name('showroom.withdraw');
        Route::post('/showroom/{listing}/sell', [VehicleListingController::class, 'sell'])
            ->middleware('throttle:30,1')
            ->name('showroom.sell');
    });
