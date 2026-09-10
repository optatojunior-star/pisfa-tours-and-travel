<?php

use App\Http\Controllers\Drivers\DriverPhotographController;
use App\Http\Controllers\Drivers\DriverPortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:driver'])->group(function (): void {
    Route::get('/driver', [DriverPortalController::class, 'index'])->name('drivers.index');
    Route::get('/driver/history', [DriverPortalController::class, 'history'])->name('drivers.history');

    // The driver's own headshot. No route parameter: the profile comes from the
    // session, so there is no other driver's photograph to reach.
    Route::post('/driver/photograph', [DriverPhotographController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('drivers.photograph.store');
    Route::delete('/driver/photograph', [DriverPhotographController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('drivers.photograph.destroy');

    /*
     * The source segment is matched against the AssignmentSource allowlist in
     * the controller, and the assignment lookup is scoped to the signed-in
     * driver, so a foreign job is a 404 rather than a 403.
     */
    Route::get('/driver/jobs/{source}/{assignment}', [DriverPortalController::class, 'show'])
        ->whereNumber('assignment')
        ->name('drivers.jobs.show');

    Route::post('/driver/jobs/{source}/{assignment}/start', [DriverPortalController::class, 'startTrip'])
        ->whereNumber('assignment')
        ->middleware('throttle:30,1')
        ->name('drivers.jobs.start');

    Route::post('/driver/jobs/{source}/{assignment}/inspection', [DriverPortalController::class, 'recordInspection'])
        ->whereNumber('assignment')
        ->middleware('throttle:30,1')
        ->name('drivers.jobs.inspection');

    Route::post('/driver/trips/{driverTrip}/complete', [DriverPortalController::class, 'completeTrip'])
        ->middleware('throttle:30,1')
        ->name('drivers.trips.complete');

    Route::post('/driver/trips/{driverTrip}/abandon', [DriverPortalController::class, 'abandonTrip'])
        ->middleware('throttle:30,1')
        ->name('drivers.trips.abandon');
});
