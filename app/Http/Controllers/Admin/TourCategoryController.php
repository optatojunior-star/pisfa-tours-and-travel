<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TourPackageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTourCategoryRequest;
use App\Models\TourCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TourCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', TourCategory::class);

        $categories = TourCategory::query()
            ->withCount([
                'tourPackages',
                'tourPackages as published_packages_count' => fn ($query) => $query
                    ->where('status', TourPackageStatus::Published->value),
            ])
            ->ordered()
            ->get();

        return view('admin.tour-categories.index', compact('categories'));
    }

    public function store(SaveTourCategoryRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validated();

        $category = DB::transaction(function () use ($validated, $request, $auditLogger): TourCategory {
            $category = TourCategory::query()->create([
                'name' => trim($validated['name']),
                'slug' => filled($validated['slug'] ?? null)
                    ? $validated['slug']
                    : $this->uniqueSlug($validated['name']),
                'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'is_active' => true,
            ]);

            $auditLogger->record(
                event: 'tour_category.created',
                auditable: $category,
                newValues: $category->only(['name', 'slug', 'is_active', 'sort_order']),
                user: $request->user(),
            );

            return $category;
        });

        return redirect()
            ->route('admin.tour-categories.index')
            ->with('success', "{$category->name} was created.");
    }

    public function update(
        SaveTourCategoryRequest $request,
        TourCategory $tourCategory,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request, $tourCategory, $auditLogger): void {
            $oldValues = $tourCategory->only(['name', 'slug', 'description', 'sort_order']);
            $tourCategory->update([
                'name' => trim($validated['name']),
                'slug' => filled($validated['slug'] ?? null) ? $validated['slug'] : $tourCategory->slug,
                'description' => filled($validated['description'] ?? null) ? trim($validated['description']) : null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
            ]);

            $auditLogger->record(
                event: 'tour_category.updated',
                auditable: $tourCategory,
                oldValues: $oldValues,
                newValues: $tourCategory->only(['name', 'slug', 'description', 'sort_order']),
                user: $request->user(),
            );
        });

        return back()->with('success', "{$tourCategory->name} was updated.");
    }

    public function toggle(Request $request, TourCategory $tourCategory, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $tourCategory);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $activate = (bool) $validated['is_active'];

        $category = DB::transaction(function () use ($activate, $request, $tourCategory, $auditLogger): TourCategory {
            $category = TourCategory::query()
                ->whereKey($tourCategory->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $activate && $category->tourPackages()
                ->where('status', TourPackageStatus::Published->value)
                ->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'Archive every published package in this category before deactivating it.',
                ]);
            }

            $old = $category->is_active;
            $category->update(['is_active' => $activate]);

            $auditLogger->record(
                event: 'tour_category.status_changed',
                auditable: $category,
                oldValues: ['is_active' => $old],
                newValues: ['is_active' => $activate],
                user: $request->user(),
            );

            return $category;
        });

        return back()->with('success', $category->is_active ? 'Category activated.' : 'Category deactivated.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tour-category';
        $slug = $base;
        $suffix = 2;

        while (TourCategory::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
