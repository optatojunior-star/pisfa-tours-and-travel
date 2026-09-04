<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Marketing\ContactMessageController;
use App\Http\Controllers\Marketing\NewsletterSubscriptionController;
use App\Http\Controllers\Marketing\PublicPageController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::controller(PublicPageController::class)->group(function (): void {
    Route::get('/', 'home')->name('home');
    Route::get('/about', 'about')->name('about');
    Route::get('/contact', 'contact')->name('contact');
    Route::get('/request-quotation', 'requestQuotation')->name('request-quotation');
    Route::get('/privacy', 'privacy')->name('privacy');
    Route::get('/terms', 'terms')->name('terms');
    // The terms PISFA actually trades on, as against the website terms
    // above. Separate pages because they answer different questions: one
    // is about using this site, the other about what happens when a hire
    // car is damaged or a safari is cancelled.
    Route::get('/booking-terms', 'bookingTerms')->name('booking-terms');
    Route::get('/cancellation-policy', 'cancellationPolicy')->name('cancellation-policy');
});

Route::post('/contact', ContactMessageController::class)
    ->middleware('throttle:5,1')
    ->name('contact.store');

Route::post('/newsletter', NewsletterSubscriptionController::class)
    ->middleware('throttle:10,1')
    ->name('newsletter.store');

Route::redirect('/quotation', '/request-quotation', 301);

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
require __DIR__.'/staff.php';
require __DIR__.'/tours.php';
require __DIR__.'/car-hire.php';
require __DIR__.'/airport-transfers.php';
require __DIR__.'/flight-inquiries.php';
require __DIR__.'/documents.php';
require __DIR__.'/payments.php';
require __DIR__.'/vehicle-imports.php';
require __DIR__.'/loyalty.php';
require __DIR__.'/reviews.php';
require __DIR__.'/billing.php';
require __DIR__.'/operations.php';
require __DIR__.'/fleet.php';
require __DIR__.'/drivers.php';
require __DIR__.'/reports.php';
require __DIR__.'/portal.php';
require __DIR__.'/governance.php';
require __DIR__.'/content.php';
require __DIR__.'/sales.php';
require __DIR__.'/accommodation.php';
require __DIR__.'/leasing.php';
require __DIR__.'/finance.php';
require __DIR__.'/corporate.php';
require __DIR__.'/messaging.php';
require __DIR__.'/pwa.php';
require __DIR__.'/seo.php';
