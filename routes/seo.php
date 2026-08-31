<?php

use App\Http\Controllers\Seo\RobotsController;
use App\Http\Controllers\Seo\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * Crawler-facing endpoints. Both are generated rather than served as static
 * files so they describe the catalogue as it is now, not as it was when
 * somebody last remembered to regenerate them.
 */
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');
