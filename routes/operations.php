<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\UnifiedBookingController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        // /dashboard already dispatches an administrator here; this is the
        // addressable URL for a bookmark or a link out of an email.
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');

        Route::get('/bookings', [UnifiedBookingController::class, 'index'])
            ->name('bookings.index');
    });
