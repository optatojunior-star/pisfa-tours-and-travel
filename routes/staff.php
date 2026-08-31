<?php

use App\Http\Controllers\Auth\StaffInvitationController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/staff/invitations/{token}', [StaffInvitationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('staff-invitations.show');
    Route::post('/staff/invitations/{token}', [StaffInvitationController::class, 'accept'])
        ->middleware('throttle:5,1')
        ->name('staff-invitations.accept');
});

Route::prefix('admin/staff')
    ->name('admin.staff.')
    ->middleware(['auth', 'verified', 'role:super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::get('/create', [StaffController::class, 'create'])->name('create');

        Route::middleware('password.confirm')->group(function (): void {
            Route::post('/', [StaffController::class, 'store'])->name('store');
            Route::patch('/{staff}', [StaffController::class, 'update'])->name('update');
            Route::post('/{staff}/resend-invitation', [StaffController::class, 'resend'])->name('resend');
        });
    });
