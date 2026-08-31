<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\OperationsHealthController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Operations\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * The public health endpoint. Says only up or down — no check names, no detail.
 * A public endpoint that lists subsystems tells an attacker what to aim at.
 */
Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('health');

/*
 * Governance sits behind the super-administrator role in addition to the
 * ordinary administration gate, and every action re-checks its policy. Reading
 * the audit trail and changing what the company says about itself are not
 * operational powers.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/audit/{auditLog}', [AuditLogController::class, 'show'])->name('audit.show');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('/settings', [SettingsController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('settings.update');

        // The detailed health report, failed jobs, and backup history. The
        // detail lives here rather than on the public endpoint because it names
        // which subsystem is failing.
        Route::get('/operations', [OperationsHealthController::class, 'index'])
            ->name('operations.index');
        Route::post('/operations/failed-jobs/{uuid}/retry', [OperationsHealthController::class, 'retry'])
            ->middleware('throttle:30,1')
            ->name('operations.failed-jobs.retry');
        Route::delete('/operations/failed-jobs/{uuid}', [OperationsHealthController::class, 'forget'])
            ->middleware('throttle:30,1')
            ->name('operations.failed-jobs.forget');
    });
