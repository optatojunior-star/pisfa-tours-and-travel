<?php

use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Reviews\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal/reviews', [ReviewController::class, 'index'])
        ->name('portal.reviews.index');
    Route::get('/portal/reviews/write/{customerTourBooking}', [ReviewController::class, 'create'])
        ->name('portal.reviews.create');
    Route::post('/portal/reviews/write/{customerTourBooking}', [ReviewController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('portal.reviews.store');
    Route::get('/portal/reviews/{customerReview}/edit', [ReviewController::class, 'edit'])
        ->name('portal.reviews.edit');
    Route::patch('/portal/reviews/{customerReview}', [ReviewController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('portal.reviews.update');
    Route::delete('/portal/reviews/{customerReview}', [ReviewController::class, 'destroy'])
        ->name('portal.reviews.destroy');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
        Route::get('/reviews/{review}', [AdminReviewController::class, 'show'])->name('reviews.show');
        Route::patch('/reviews/{review}/moderate', [AdminReviewController::class, 'moderate'])
            ->name('reviews.moderate');
        Route::post('/reviews/{review}/reply', [AdminReviewController::class, 'reply'])
            ->middleware('throttle:20,1')
            ->name('reviews.reply');
    });
