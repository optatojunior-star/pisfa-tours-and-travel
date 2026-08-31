<?php

use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        /*
         * The dataset segment is matched against the ExportDataset allowlist,
         * and each dataset re-checks the actor's role before a single row is
         * written. Throttled because an export is expensive.
         */
        Route::get('/reports/export/{dataset}', [ReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('reports.export');
    });
