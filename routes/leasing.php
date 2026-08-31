<?php

use App\Http\Controllers\Admin\LeaseApplicationController as AdminLeaseApplicationController;
use App\Http\Controllers\Admin\VehicleLeaseController;
use App\Http\Controllers\Leasing\LeaseApplicationController;
use Illuminate\Support\Facades\Route;

/*
 * Offering a vehicle is open to the public, including guests: the form is the
 * first contact PISFA has with most owners. The lease agreement itself needs an
 * account, because that is where payouts are addressed.
 */
Route::get('/lease-your-car', [LeaseApplicationController::class, 'create'])->name('leasing.create');

Route::post('/lease-your-car', [LeaseApplicationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('leasing.store');

// A reference is the guest's only key, so the pattern is constrained here as
// well as looked up exactly.
Route::get('/lease-your-car/{reference}', [LeaseApplicationController::class, 'show'])
    ->where('reference', 'LEASE-[A-Z0-9]{26}')
    ->name('leasing.show');

Route::get('/portal/leases/{ownerLease}', [LeaseApplicationController::class, 'lease'])
    ->middleware(['auth', 'verified'])
    ->name('portal.leases.show');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/leasing/offers', [AdminLeaseApplicationController::class, 'index'])
            ->name('leasing.applications.index');
        Route::get('/leasing/offers/{application}', [AdminLeaseApplicationController::class, 'show'])
            ->name('leasing.applications.show');
        Route::post('/leasing/offers/{application}/review', [AdminLeaseApplicationController::class, 'review'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.review');
        Route::post('/leasing/offers/{application}/inspection', [AdminLeaseApplicationController::class, 'arrangeInspection'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.inspection');
        Route::post('/leasing/offers/{application}/findings', [AdminLeaseApplicationController::class, 'recordInspection'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.findings');
        Route::post('/leasing/offers/{application}/approve', [AdminLeaseApplicationController::class, 'approve'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.approve');
        Route::post('/leasing/offers/{application}/decline', [AdminLeaseApplicationController::class, 'decline'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.decline');
        Route::post('/leasing/offers/{application}/withdraw', [AdminLeaseApplicationController::class, 'withdraw'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.withdraw');
        Route::post('/leasing/offers/{application}/assign', [AdminLeaseApplicationController::class, 'assign'])
            ->middleware('throttle:60,1')
            ->name('leasing.applications.assign');

        Route::get('/leasing/new', [VehicleLeaseController::class, 'create'])
            ->name('leasing.leases.create');
        Route::get('/leasing', [VehicleLeaseController::class, 'index'])
            ->name('leasing.leases.index');
        Route::post('/leasing', [VehicleLeaseController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('leasing.leases.store');

        Route::get('/leasing/{lease}', [VehicleLeaseController::class, 'show'])
            ->name('leasing.leases.show');
        Route::get('/leasing/{lease}/edit', [VehicleLeaseController::class, 'edit'])
            ->name('leasing.leases.edit');
        Route::patch('/leasing/{lease}', [VehicleLeaseController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('leasing.leases.update');

        Route::post('/leasing/{lease}/activate', [VehicleLeaseController::class, 'activate'])
            ->middleware('throttle:30,1')
            ->name('leasing.leases.activate');
        Route::post('/leasing/{lease}/suspend', [VehicleLeaseController::class, 'suspend'])
            ->middleware('throttle:30,1')
            ->name('leasing.leases.suspend');
        Route::post('/leasing/{lease}/end', [VehicleLeaseController::class, 'end'])
            ->middleware('throttle:30,1')
            ->name('leasing.leases.end');

        Route::post('/leasing/{lease}/statements', [VehicleLeaseController::class, 'calculatePayout'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.calculate');
        Route::post('/leasing/{lease}/statements/{payout}/deductions', [VehicleLeaseController::class, 'applyDeductions'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.deductions');
        Route::post('/leasing/{lease}/statements/{payout}/recover-expenses', [VehicleLeaseController::class, 'recoverExpenses'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.recover');
        Route::post('/leasing/{lease}/statements/{payout}/approve', [VehicleLeaseController::class, 'approvePayout'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.approve');
        Route::post('/leasing/{lease}/statements/{payout}/paid', [VehicleLeaseController::class, 'payPayout'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.paid');
        Route::post('/leasing/{lease}/statements/{payout}/reopen', [VehicleLeaseController::class, 'reopenPayout'])
            ->middleware('throttle:30,1')
            ->name('leasing.payouts.reopen');
    });
