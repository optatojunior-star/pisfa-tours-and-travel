<?php

use App\Http\Controllers\Admin\CarHireBookingController as AdminCarHireBookingController;
use App\Http\Controllers\Admin\CataloguePhotographController;
use App\Http\Controllers\Admin\VehicleController;
use App\Http\Controllers\CarHire\CarHireBookingController;
use App\Http\Controllers\CarHire\CarHireCatalogueController;
use App\Http\Controllers\CarHire\CarHireContractController;
use App\Http\Controllers\CarHire\CarHireDocumentController;
use App\Http\Controllers\CarHire\CarHirePortalController;
use App\Http\Controllers\CarHire\SelfDriveApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/car-hire', [CarHireCatalogueController::class, 'index'])->name('car-hire.index');
Route::get('/car-hire/{vehicle}', [CarHireCatalogueController::class, 'show'])
    ->where('vehicle', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('car-hire.show');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/car-hire/{vehicle}/book', [CarHireBookingController::class, 'create'])
        ->where('vehicle', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('car-hire-bookings.create');
    Route::post('/car-hire/{vehicle}/bookings', [CarHireBookingController::class, 'store'])
        ->where('vehicle', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->middleware('throttle:10,1')
        ->name('car-hire-bookings.store');

    Route::get('/portal/car-hire', [CarHirePortalController::class, 'index'])
        ->name('portal.car-hire-bookings.index');
    Route::get('/portal/car-hire/{customerCarHireBooking}', [CarHirePortalController::class, 'show'])
        ->name('portal.car-hire-bookings.show');
    Route::patch('/portal/car-hire/{customerCarHireBooking}/cancel', [CarHirePortalController::class, 'cancel'])
        ->middleware('throttle:6,1')
        ->name('portal.car-hire-bookings.cancel');

    Route::get('/portal/car-hire/{customerCarHireBooking}/self-drive', [SelfDriveApplicationController::class, 'edit'])
        ->name('portal.car-hire-bookings.self-drive.edit');
    Route::patch('/portal/car-hire/{customerCarHireBooking}/self-drive', [SelfDriveApplicationController::class, 'update'])
        ->name('portal.car-hire-bookings.self-drive.update');
    Route::patch('/portal/car-hire/{customerCarHireBooking}/self-drive/submit', [SelfDriveApplicationController::class, 'submit'])
        ->middleware('throttle:6,1')
        ->name('portal.car-hire-bookings.self-drive.submit');

    Route::post('/portal/car-hire/{customerCarHireBooking}/documents', [CarHireDocumentController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('portal.car-hire-bookings.documents.store');
    Route::delete('/portal/car-hire/{customerCarHireBooking}/documents/{carHireDocument}', [CarHireDocumentController::class, 'destroy'])
        ->name('portal.car-hire-bookings.documents.destroy');
    Route::get('/portal/car-hire/{customerCarHireBooking}/documents/{carHireDocument}', [CarHireDocumentController::class, 'downloadForCustomer'])
        ->middleware('throttle:30,1')
        ->name('portal.car-hire-bookings.documents.download');

    Route::get('/portal/car-hire/{customerCarHireBooking}/contracts/{carHireContract}', [CarHireContractController::class, 'show'])
        ->name('portal.car-hire-bookings.contracts.show');
    Route::get('/portal/car-hire/{customerCarHireBooking}/contracts/{carHireContract}/pdf', [CarHireContractController::class, 'download'])
        ->middleware('throttle:30,1')
        ->name('portal.car-hire-bookings.contracts.download');
    Route::patch('/portal/car-hire/{customerCarHireBooking}/contracts/{carHireContract}/accept', [CarHireContractController::class, 'accept'])
        ->middleware('throttle:6,1')
        ->name('portal.car-hire-bookings.contracts.accept');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/vehicles', [VehicleController::class, 'index'])->name('vehicles.index');
        // Before /vehicles/{vehicle}, or the slug binding swallows it.
        Route::get('/vehicles/new', [VehicleController::class, 'choose'])->name('vehicles.choose');
        Route::get('/vehicles/create', [VehicleController::class, 'create'])->name('vehicles.create');
        Route::post('/vehicles', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->name('vehicles.show');
        Route::get('/vehicles/{vehicle}/edit', [VehicleController::class, 'edit'])->name('vehicles.edit');
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update'])->name('vehicles.update');
        Route::patch('/vehicles/{vehicle}/status', [VehicleController::class, 'status'])->name('vehicles.status');
        Route::post('/vehicles/{vehicle}/rates', [VehicleController::class, 'storeRate'])->name('vehicles.rates.store');
        // Scoped, so a photograph belonging to another vehicle is a 404
        // rather than a deletion on the wrong record.
        Route::delete('/vehicles/{vehicle}/photographs/{medium}', [CataloguePhotographController::class, 'destroyVehiclePhotograph'])
            ->scopeBindings()
            ->name('vehicles.media.destroy');

        Route::get('/car-hire-bookings', [AdminCarHireBookingController::class, 'index'])->name('car-hire-bookings.index');
        Route::get('/car-hire-bookings/{carHireBooking}', [AdminCarHireBookingController::class, 'show'])->name('car-hire-bookings.show');
        Route::patch('/car-hire-bookings/{carHireBooking}/transition', [AdminCarHireBookingController::class, 'transition'])->name('car-hire-bookings.transition');
        Route::patch('/car-hire-bookings/{carHireBooking}/assignment', [AdminCarHireBookingController::class, 'assign'])->name('car-hire-bookings.assignment');
        Route::patch('/car-hire-bookings/{carHireBooking}/self-drive/review', [AdminCarHireBookingController::class, 'review'])->name('car-hire-bookings.self-drive.review');
        Route::patch('/car-hire-bookings/{carHireBooking}/self-drive/verify-originals', [AdminCarHireBookingController::class, 'verifyOriginals'])->name('car-hire-bookings.self-drive.verify-originals');
        Route::get('/car-hire-bookings/{carHireBooking}/documents/{carHireDocument}', [CarHireDocumentController::class, 'downloadForAdministration'])
            ->middleware('throttle:60,1')
            ->name('car-hire-bookings.documents.download');
    });
