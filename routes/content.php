<?php

use App\Http\Controllers\Admin\PostController as AdminPostController;
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
