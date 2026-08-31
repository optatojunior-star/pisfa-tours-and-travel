<?php

use App\Http\Controllers\Admin\InboxController;
use App\Http\Controllers\Messaging\ChatController;
use App\Http\Controllers\Messaging\WhatsAppWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * The chat widget is open to guests. Its endpoints are throttled and its
 * entitlement comes from the session, not from the reference in the URL — see
 * ChatController.
 */
Route::prefix('chat')->name('chat.')->group(function (): void {
    Route::post('/', [ChatController::class, 'start'])
        ->middleware('throttle:10,1')
        ->name('start');

    Route::get('/{reference}/messages', [ChatController::class, 'messages'])
        ->where('reference', 'CHT-[A-Z0-9]+')
        // Generous, because this is the poll: at an eight second interval a
        // single open widget makes about eight requests a minute, and a tab
        // left open all day must not lock the visitor out of their own chat.
        ->middleware('throttle:120,1')
        ->name('messages');

    Route::post('/{reference}/messages', [ChatController::class, 'send'])
        ->where('reference', 'CHT-[A-Z0-9]+')
        ->middleware('throttle:20,1')
        ->name('send');
});

/*
 * The provider callback. No session, no CSRF token, and no authentication
 * middleware — its only credential is the signature, verified before the
 * payload is read.
 */
Route::prefix('webhooks/whatsapp')->name('webhooks.whatsapp.')->group(function (): void {
    Route::get('/', [WhatsAppWebhookController::class, 'verify'])->name('verify');
    Route::post('/', WhatsAppWebhookController::class)
        ->middleware('throttle:600,1')
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('receive');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        Route::get('/inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
        Route::post('/inbox/{conversation}/reply', [InboxController::class, 'reply'])
            ->middleware('throttle:60,1')
            ->name('inbox.reply');
        Route::post('/inbox/{conversation}/assign', [InboxController::class, 'assign'])
            ->middleware('throttle:60,1')
            ->name('inbox.assign');
        Route::post('/inbox/{conversation}/resolve', [InboxController::class, 'resolve'])
            ->middleware('throttle:60,1')
            ->name('inbox.resolve');
        Route::post('/inbox/{conversation}/reopen', [InboxController::class, 'reopen'])
            ->middleware('throttle:60,1')
            ->name('inbox.reopen');
        Route::post('/inbox/{conversation}/close', [InboxController::class, 'close'])
            ->middleware('throttle:60,1')
            ->name('inbox.close');
    });
