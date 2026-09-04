<?php

use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Reviews\PublicReviewController;
use App\Http\Controllers\Reviews\ReviewController;
use Illuminate\Support\Facades\Route;

// Open to anyone reading the page, because most PISFA customers arrange
// their trip over WhatsApp and never make an account — a review system only
// registered customers can reach is one nobody writes in.
//
// Nothing is relaxed about publication: this lands Pending like every other
// review, and a moderator remains the only route to the public page. The
// throttle and the one-per-email rule are what replace booking eligibility.
Route::post('/tours/{tourPackage}/reviews', [PublicReviewController::class, 'store'])
    ->where('tourPackage', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->middleware('throttle:5,60')
    ->name('tours.reviews.store');

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
