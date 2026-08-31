<?php

use App\Http\Controllers\Admin\CorporateAccountController;
use App\Http\Controllers\Admin\GroupBookingController as AdminGroupBookingController;
use App\Http\Controllers\Corporate\GroupBookingController;
use Illuminate\Support\Facades\Route;

/*
 * Group bookings are open to any signed-in customer — a school trip or a family
 * reunion is a group without being a company. Company accounts themselves are
 * run by PISFA staff, with the account's own administrator able to manage who
 * else is on it.
 */
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/portal/groups', [GroupBookingController::class, 'index'])->name('portal.groups.index');
    Route::post('/portal/groups', [GroupBookingController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('portal.groups.store');
    Route::get('/portal/groups/{group}', [GroupBookingController::class, 'show'])->name('portal.groups.show');
    Route::patch('/portal/groups/{group}', [GroupBookingController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('portal.groups.update');
    Route::post('/portal/groups/{group}/cancel', [GroupBookingController::class, 'cancel'])
        ->middleware('throttle:20,1')
        ->name('portal.groups.cancel');

    Route::post('/portal/groups/{group}/travelers', [GroupBookingController::class, 'addTraveler'])
        ->middleware('throttle:120,1')
        ->name('portal.groups.travelers.store');
    Route::patch('/portal/groups/{group}/travelers/{traveler}', [GroupBookingController::class, 'updateTraveler'])
        ->middleware('throttle:120,1')
        ->name('portal.groups.travelers.update');
    Route::delete('/portal/groups/{group}/travelers/{traveler}', [GroupBookingController::class, 'removeTraveler'])
        ->middleware('throttle:120,1')
        ->name('portal.groups.travelers.destroy');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        // Groups are declared before the {account} routes so that
        // /admin/corporate/groups is never read as an account slug.
        Route::get('/corporate/groups', [AdminGroupBookingController::class, 'index'])
            ->name('corporate.groups.index');
        Route::get('/corporate/groups/{group}', [AdminGroupBookingController::class, 'show'])
            ->name('corporate.groups.show');
        Route::post('/corporate/groups/{group}/price', [AdminGroupBookingController::class, 'price'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.price');
        Route::post('/corporate/groups/{group}/quote', [AdminGroupBookingController::class, 'quote'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.quote');
        Route::post('/corporate/groups/{group}/manifest', [AdminGroupBookingController::class, 'requestManifest'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.manifest');
        Route::post('/corporate/groups/{group}/confirm', [AdminGroupBookingController::class, 'confirm'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.confirm');
        Route::post('/corporate/groups/{group}/start', [AdminGroupBookingController::class, 'start'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.start');
        Route::post('/corporate/groups/{group}/complete', [AdminGroupBookingController::class, 'complete'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.complete');
        Route::post('/corporate/groups/{group}/cancel', [AdminGroupBookingController::class, 'cancel'])
            ->middleware('throttle:60,1')
            ->name('corporate.groups.cancel');

        Route::get('/corporate/new', [CorporateAccountController::class, 'create'])
            ->name('corporate.create');
        Route::get('/corporate', [CorporateAccountController::class, 'index'])
            ->name('corporate.index');
        Route::post('/corporate', [CorporateAccountController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('corporate.store');

        Route::get('/corporate/{account}', [CorporateAccountController::class, 'show'])
            ->name('corporate.show');
        Route::get('/corporate/{account}/edit', [CorporateAccountController::class, 'edit'])
            ->name('corporate.edit');
        Route::patch('/corporate/{account}', [CorporateAccountController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('corporate.update');
        Route::patch('/corporate/{account}/terms', [CorporateAccountController::class, 'updateTerms'])
            ->middleware('throttle:30,1')
            ->name('corporate.terms');

        Route::post('/corporate/{account}/activate', [CorporateAccountController::class, 'activate'])
            ->middleware('throttle:30,1')
            ->name('corporate.activate');
        Route::post('/corporate/{account}/suspend', [CorporateAccountController::class, 'suspend'])
            ->middleware('throttle:30,1')
            ->name('corporate.suspend');
        Route::post('/corporate/{account}/close', [CorporateAccountController::class, 'close'])
            ->middleware('throttle:30,1')
            ->name('corporate.close');
        Route::post('/corporate/{account}/reopen', [CorporateAccountController::class, 'reopen'])
            ->middleware('throttle:30,1')
            ->name('corporate.reopen');

        Route::post('/corporate/{account}/members', [CorporateAccountController::class, 'addMember'])
            ->middleware('throttle:60,1')
            ->name('corporate.members.store');
        Route::delete('/corporate/{account}/members/{member}', [CorporateAccountController::class, 'removeMember'])
            ->middleware('throttle:60,1')
            ->name('corporate.members.destroy');
    });
