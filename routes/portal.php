<?php

use App\Http\Controllers\Portal\NotificationController;
use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:customer'])->group(function (): void {
    Route::get('/portal', [PortalController::class, 'index'])->name('portal.index');
    Route::get('/portal/activity', [PortalController::class, 'activity'])->name('portal.activity');
    Route::get('/portal/documents', [PortalController::class, 'documents'])->name('portal.documents');

    Route::get('/portal/messages', [NotificationController::class, 'index'])
        ->name('portal.notifications.index');

    // The notification id is a UUID, and the lookup is scoped through the
    // notifiable relationship, so one customer cannot open another's message.
    Route::post('/portal/messages/{notification}/read', [NotificationController::class, 'read'])
        ->whereUuid('notification')
        ->middleware('throttle:60,1')
        ->name('portal.notifications.read');

    Route::post('/portal/messages/read-all', [NotificationController::class, 'readAll'])
        ->middleware('throttle:20,1')
        ->name('portal.notifications.read-all');
});
