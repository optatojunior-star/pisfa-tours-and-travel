<?php

use App\Http\Controllers\Admin\ExpenseReviewController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\PayslipController;
use Illuminate\Support\Facades\Route;

/*
 * Anybody on the payroll can claim what they spent and read their own payslips.
 * The review queue is staff; payroll itself is super-administrator only, because
 * a run discloses what every colleague earns.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/portal/expenses', [ExpenseController::class, 'index'])->name('portal.expenses.index');
    Route::post('/portal/expenses', [ExpenseController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('portal.expenses.store');
    Route::patch('/portal/expenses/{expense}', [ExpenseController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('portal.expenses.update');
    Route::post('/portal/expenses/{expense}/submit', [ExpenseController::class, 'submit'])
        ->middleware('throttle:30,1')
        ->name('portal.expenses.submit');

    Route::get('/portal/payslips', [PayslipController::class, 'index'])->name('portal.payslips.index');
    Route::get('/portal/payslips/{payslip}', [PayslipController::class, 'show'])->name('portal.payslips.show');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/expenses', [ExpenseReviewController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/{expense}', [ExpenseReviewController::class, 'show'])->name('expenses.show');
        Route::post('/expenses/{expense}/approve', [ExpenseReviewController::class, 'approve'])
            ->middleware('throttle:60,1')
            ->name('expenses.approve');
        Route::post('/expenses/{expense}/reject', [ExpenseReviewController::class, 'reject'])
            ->middleware('throttle:60,1')
            ->name('expenses.reject');
        Route::post('/expenses/{expense}/return', [ExpenseReviewController::class, 'returnToDraft'])
            ->middleware('throttle:60,1')
            ->name('expenses.return');
        Route::post('/expenses/{expense}/reimburse', [ExpenseReviewController::class, 'reimburse'])
            ->middleware('throttle:60,1')
            ->name('expenses.reimburse');
    });

/*
 * Payroll carries the super-administrator role in the route as well as in the
 * policy: a run should not be reachable by a manager even for a redirect.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
        Route::post('/payroll', [PayrollController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('payroll.store');
        Route::get('/payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
        Route::post('/payroll/{run}/lines', [PayrollController::class, 'saveLine'])
            ->middleware('throttle:60,1')
            ->name('payroll.lines.save');
        Route::delete('/payroll/{run}/lines/{line}', [PayrollController::class, 'removeLine'])
            ->middleware('throttle:60,1')
            ->name('payroll.lines.remove');
        Route::post('/payroll/{run}/approve', [PayrollController::class, 'approve'])
            ->middleware('throttle:30,1')
            ->name('payroll.approve');
        Route::post('/payroll/{run}/reopen', [PayrollController::class, 'reopen'])
            ->middleware('throttle:30,1')
            ->name('payroll.reopen');
        Route::post('/payroll/{run}/paid', [PayrollController::class, 'markPaid'])
            ->middleware('throttle:30,1')
            ->name('payroll.paid');
        Route::post('/payroll/{run}/cancel', [PayrollController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('payroll.cancel');
    });
