<?php

use App\Http\Controllers\Admin\VehicleImportController as AdminVehicleImportController;
use App\Http\Controllers\VehicleImports\VehicleImportController;
use App\Http\Controllers\VehicleImports\VehicleImportPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/vehicle-imports', [VehicleImportController::class, 'create'])
    ->name('vehicle-imports.create');

Route::post('/vehicle-imports', [VehicleImportController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('vehicle-imports.store');

// Public tracking by unguessable token. Throttled because the token is the only
// credential, so a brute-force attempt must be expensive as well as futile
// against 32 bytes of entropy.
Route::get('/track/import/{token}', [VehicleImportController::class, 'track'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:30,1')
    ->name('vehicle-imports.track');

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/imports', [VehicleImportPortalController::class, 'index'])
        ->name('portal.vehicle-imports.index');
    Route::get('/portal/imports/{customerVehicleImport}', [VehicleImportPortalController::class, 'show'])
        ->name('portal.vehicle-imports.show');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/vehicle-imports', [AdminVehicleImportController::class, 'index'])
            ->name('vehicle-imports.index');
        Route::get('/vehicle-imports/{vehicleImport}', [AdminVehicleImportController::class, 'show'])
            ->name('vehicle-imports.show');
        Route::post('/vehicle-imports/{vehicleImport}/quote', [AdminVehicleImportController::class, 'quote'])
            ->middleware('throttle:20,1')
            ->name('vehicle-imports.quote');
        Route::patch('/vehicle-imports/{vehicleImport}/transition', [AdminVehicleImportController::class, 'transition'])
            ->name('vehicle-imports.transition');
    });
