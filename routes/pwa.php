<?php

use App\Http\Controllers\Pwa\ProgressiveWebAppController;
use Illuminate\Support\Facades\Route;

/*
 * The installable app.
 *
 * All three are public and unauthenticated: a service worker is fetched without
 * the page's session in some browsers, and the offline page has to be reachable
 * precisely when nothing else is.
 *
 * The worker is served from the site root so its scope covers the whole
 * application — a worker under a subdirectory could only control that
 * subdirectory.
 */
Route::get('/manifest.webmanifest', [ProgressiveWebAppController::class, 'manifest'])
    ->name('pwa.manifest');

Route::get('/sw.js', [ProgressiveWebAppController::class, 'serviceWorker'])
    ->name('pwa.service-worker');

Route::get('/offline', [ProgressiveWebAppController::class, 'offline'])
    ->name('pwa.offline');
