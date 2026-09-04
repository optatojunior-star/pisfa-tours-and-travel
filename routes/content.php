<?php

use App\Http\Controllers\Admin\MediaLibraryController;
use App\Http\Controllers\Admin\PostController as AdminPostController;
use App\Http\Controllers\Admin\ServiceImageController;
use App\Http\Controllers\Admin\TeamMemberController;
use App\Http\Controllers\Content\BlogController;
use Illuminate\Support\Facades\Route;

Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');

// The slug pattern is constrained here as well as validated on save, so a
// request for a path that could never be a slug never reaches a query.
Route::get('/blog/{post}', [BlogController::class, 'show'])
    ->where('post', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('blog.show');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        Route::get('/posts/new', [AdminPostController::class, 'create'])->name('posts.create');
        Route::get('/posts', [AdminPostController::class, 'index'])->name('posts.index');
        Route::post('/posts', [AdminPostController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('posts.store');
        Route::get('/posts/{post}/edit', [AdminPostController::class, 'edit'])->name('posts.edit');
        Route::patch('/posts/{post}', [AdminPostController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('posts.update');
        Route::post('/posts/{post}/publish', [AdminPostController::class, 'publish'])
            ->middleware('throttle:30,1')
            ->name('posts.publish');
        Route::post('/posts/{post}/unpublish', [AdminPostController::class, 'unpublish'])
            ->middleware('throttle:30,1')
            ->name('posts.unpublish');
        Route::post('/posts/{post}/archive', [AdminPostController::class, 'archive'])
            ->middleware('throttle:30,1')
            ->name('posts.archive');
    });

/*
 * The image library. Uploading was the one part of F27 that was never built:
 * the storage layer, inspector and actions all existed, with no way for a
 * person to reach them.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'verified', 'role:staff,manager,super_admin', '2fa.required'])
    ->group(function (): void {
        // The about page's people. Publishing is its own step, so a profile
        // can be written and held back rather than going live as it is typed.
        Route::get('/team', [TeamMemberController::class, 'index'])->name('team.index');
        Route::get('/team/new', [TeamMemberController::class, 'create'])->name('team.create');
        Route::post('/team', [TeamMemberController::class, 'store'])->name('team.store');
        Route::get('/team/{team}/edit', [TeamMemberController::class, 'edit'])->name('team.edit');
        Route::patch('/team/{team}', [TeamMemberController::class, 'update'])->name('team.update');
        Route::post('/team/{team}/publish', [TeamMemberController::class, 'publish'])->name('team.publish');
        Route::post('/team/{team}/unpublish', [TeamMemberController::class, 'unpublish'])->name('team.unpublish');
        Route::delete('/team/{team}', [TeamMemberController::class, 'destroy'])->name('team.destroy');

        Route::get('/service-images', [ServiceImageController::class, 'index'])->name('service-images.index');
        Route::post('/service-images', [ServiceImageController::class, 'store'])->name('service-images.store');
        Route::delete('/service-images/{serviceImage}', [ServiceImageController::class, 'destroy'])->name('service-images.destroy');

        Route::get('/media', [MediaLibraryController::class, 'index'])->name('media.index');
        Route::post('/media', [MediaLibraryController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('media.store');
        Route::get('/media/picker', [MediaLibraryController::class, 'picker'])->name('media.picker');
        Route::delete('/media/{document}', [MediaLibraryController::class, 'destroy'])
            ->middleware('throttle:30,1')
            ->name('media.destroy');
    });
