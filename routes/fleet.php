<?php

use App\Http\Controllers\Admin\FleetController;
use App\Http\Controllers\Admin\VehicleFuelController;
use App\Http\Controllers\Admin\VehicleMaintenanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/fleet', [FleetController::class, 'index'])->name('fleet.index');
        Route::get('/fleet/{vehicle}', [FleetController::class, 'show'])->name('fleet.show');

        Route::post('/fleet/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('fleet.maintenance.store');

        Route::post('/fleet/{vehicle}/fuel', [VehicleFuelController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('fleet.fuel.store');

        Route::patch('/maintenance/{maintenance}', [VehicleMaintenanceController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('fleet.maintenance.update');
        Route::post('/maintenance/{maintenance}/start', [VehicleMaintenanceController::class, 'start'])
            ->middleware('throttle:30,1')
            ->name('fleet.maintenance.start');
        Route::post('/maintenance/{maintenance}/complete', [VehicleMaintenanceController::class, 'complete'])
            ->middleware('throttle:30,1')
            ->name('fleet.maintenance.complete');
        Route::post('/maintenance/{maintenance}/cancel', [VehicleMaintenanceController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('fleet.maintenance.cancel');
    });
