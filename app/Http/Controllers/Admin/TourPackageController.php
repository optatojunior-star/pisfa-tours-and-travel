<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Tours\SaveTourPackage;
use App\Enums\DocumentCategory;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTourPackageRequest;
use App\Models\TourCategory;
use App\Models\TourPackage;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TourPackageController extends Controller
{
    use HandlesImageUploads;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TourPackage::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:140'],
            'status' => ['nullable', Rule::enum(TourPackageStatus::class)],
            'availability' => ['nullable', Rule::in(['upcoming', 'none'])],
        ]);

        $query = TourPackage::query()
            ->with(['category', 'coverMedia'])
            ->withCount([
                'departures',
                'bookings',
                'departures as upcoming_departures_count' => fn ($departure) => $departure
                    ->where('status', TourDepartureStatus::Scheduled->value)
                    ->where('starts_at', '>', now()),
            ]);

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        if (filled($filters['category'] ?? null)) {
            $query->whereHas('category', fn ($category) => $category
                ->where('slug', $filters['category']));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (($filters['availability'] ?? null) === 'upcoming') {
            $query->whereHas('departures', fn ($departure) => $departure
                ->where('status', TourDepartureStatus::Scheduled->value)
                ->where('starts_at', '>', now()));
        } elseif (($filters['availability'] ?? null) === 'none') {
            $query->whereDoesntHave('departures', fn ($departure) => $departure
                ->where('status', TourDepartureStatus::Scheduled->value)
                ->where('starts_at', '>', now()));
        }

        $packages = $query->latest('updated_at')->latest('id')->paginate(20)->withQueryString();
        $categories = TourCategory::query()->ordered()->get();

        return view('admin.tours.index', compact('packages', 'categories', 'filters'));
    }

    public function create(): View
    {
        $this->authorize('create', TourPackage::class);

        $package = new TourPackage([
            'currency' => config('pisfa.currency.default', 'UGX'),
            'min_travelers' => 1,
            'max_travelers' => 12,
            'cancellation_cutoff_hours' => config('tours.default_cancellation_cutoff_hours', 48),
            'duration_days' => 1,
        ]);
        $categories = TourCategory::query()->active()->ordered()->get();

        return view('admin.tours.create', compact('package', 'categories'));
    }

    public function store(SaveTourPackageRequest $request, SaveTourPackage $saveTourPackage): RedirectResponse
    {
        $attributes = $request->validated();
        $attributes['status'] = TourPackageStatus::Draft->value;

        $package = $saveTourPackage->execute(
            actor: $request->user(),
            attributes: $attributes,
        );

        $rejected = $this->storeTourPhotographs($request, $package);

        return $this->withRejectedImages(
            redirect()->route('admin.tours.show', $package)
                ->with('success', 'Tour package saved as a draft. Review it, then publish when ready.'),
            $rejected,
        );
    }

    public function show(TourPackage $tourPackage): View
    {
        $this->authorize('view', $tourPackage);

        $package = $tourPackage->load([
            'category',
            'media',
            'itineraryDays',
            'inclusions',
            'exclusions',
            'departures' => fn ($departure) => $departure
                ->withSum([
                    'bookings as reserved_seats' => fn ($booking) => $booking
                        ->whereIn('status', TourBookingStatus::capacityHoldingValues()),
                ], 'traveler_count')
                ->orderBy('starts_at'),
        ])->loadCount('bookings');

        return view('admin.tours.show', compact('package'));
    }

    public function edit(TourPackage $tourPackage): View
    {
        $this->authorize('update', $tourPackage);

        $package = $tourPackage->load(['media', 'itineraryDays', 'inclusions', 'exclusions']);
        $categories = TourCategory::query()->active()->ordered()->get();

        return view('admin.tours.edit', compact('package', 'categories'));
    }

    public function update(
        SaveTourPackageRequest $request,
        TourPackage $tourPackage,
        SaveTourPackage $saveTourPackage,
    ): RedirectResponse {
        $attributes = $request->validated();

        $package = $saveTourPackage->execute(
            actor: $request->user(),
            attributes: $attributes,
            package: $tourPackage,
        );

        $rejected = $this->storeTourPhotographs($request, $package);

        return $this->withRejectedImages(
            redirect()->route('admin.tours.show', $package)->with('success', 'Tour package updated.'),
            $rejected,
        );
    }

    public function publish(Request $request, TourPackage $tourPackage, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('publish', $tourPackage);

        DB::transaction(function () use ($request, $tourPackage, $auditLogger): void {
            $category = TourCategory::query()
                ->whereKey($tourPackage->tour_category_id)
                ->lockForUpdate()
                ->firstOrFail();
            $package = TourPackage::query()->lockForUpdate()->findOrFail($tourPackage->getKey());

            if ($package->tour_category_id !== $category->getKey()) {
                throw ValidationException::withMessages([
                    'category_id' => 'The package category changed while publishing. Reload the page and try again.',
                ]);
            }

            if ($package->status === TourPackageStatus::Published) {
                return;
            }

            if ($package->status !== TourPackageStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Restore this archived package to draft before publishing it.',
                ]);
            }

            $errors = [];
            if (! $category->is_active) {
                $errors['category_id'] = 'The package category must be active before publication.';
            }
            if (! $package->itineraryDays()->exists()) {
                $errors['itinerary'] = 'Add at least one itinerary day before publication.';
            }
            $dayNumbers = $package->itineraryDays()->orderBy('day_number')->pluck('day_number')->all();
            if ($dayNumbers !== range(1, $package->duration_days)) {
                $errors['itinerary'] = 'Add one itinerary entry for every day, numbered in order.';
            }
            if (! $package->inclusions()->exists()) {
                $errors['inclusions'] = 'Add at least one inclusion before publication.';
            }
            if (! $package->exclusions()->exists()) {
                $errors['exclusions'] = 'Add at least one exclusion before publication.';
            }
            if ($package->media()->where('is_cover', true)->count() !== 1) {
                $errors['media'] = 'Select exactly one cover image before publication.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $oldValues = ['status' => $package->status->value, 'published_at' => $package->published_at?->toIso8601String()];
            $package->update([
                'status' => TourPackageStatus::Published,
                'published_at' => now(),
                'updated_by_user_id' => $request->user()->getKey(),
            ]);

            $auditLogger->record(
                event: 'tour_package.published',
                auditable: $package,
                oldValues: $oldValues,
                newValues: ['status' => TourPackageStatus::Published->value, 'published_at' => $package->published_at?->toIso8601String()],
                user: $request->user(),
            );
        });

        return back()->with('success', 'Tour package published.');
    }

    public function archive(Request $request, TourPackage $tourPackage, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('archive', $tourPackage);

        DB::transaction(function () use ($request, $tourPackage, $auditLogger): void {
            $package = TourPackage::query()->lockForUpdate()->findOrFail($tourPackage->getKey());

            if ($package->status === TourPackageStatus::Archived) {
                return;
            }

            $oldStatus = $package->status;
            $package->update([
                'status' => TourPackageStatus::Archived,
                'updated_by_user_id' => $request->user()->getKey(),
            ]);

            $auditLogger->record(
                event: 'tour_package.archived',
                auditable: $package,
                oldValues: ['status' => $oldStatus->value],
                newValues: ['status' => TourPackageStatus::Archived->value],
                user: $request->user(),
            );
        });

        return back()->with('success', 'Tour package archived. Existing bookings were preserved.');
    }

    public function restore(Request $request, TourPackage $tourPackage, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('update', $tourPackage);

        DB::transaction(function () use ($request, $tourPackage, $auditLogger): void {
            $package = TourPackage::query()->lockForUpdate()->findOrFail($tourPackage->getKey());

            if ($package->status === TourPackageStatus::Draft) {
                return;
            }

            if ($package->status !== TourPackageStatus::Archived) {
                throw ValidationException::withMessages([
                    'status' => 'Only an archived package can be restored to draft.',
                ]);
            }

            $oldValues = [
                'status' => $package->status->value,
                'published_at' => $package->published_at?->toIso8601String(),
            ];
            $package->update([
                'status' => TourPackageStatus::Draft,
                'published_at' => null,
                'updated_by_user_id' => $request->user()->getKey(),
            ]);

            $auditLogger->record(
                event: 'tour_package.restored_to_draft',
                auditable: $package,
                oldValues: $oldValues,
                newValues: [
                    'status' => TourPackageStatus::Draft->value,
                    'published_at' => null,
                ],
                user: $request->user(),
            );
        });

        return back()->with('success', 'Tour package restored to draft.');
    }

    /**
     * Stores uploaded photographs and points the package's media rows at them.
     *
     * A tour keeps its images in TourPackageMedia, which holds a URL string
     * rather than a document. Rather than give tours a second, weaker upload
     * path, the file goes through StoreDocument — the same inspection every
     * other upload gets, reading the real content rather than the extension —
     * and the resulting public URL is written into the row the views already
     * read. One storage path, one set of checks, and nothing that displays a
     * tour has to change.
     *
     * @return list<string>
     */
    private function storeTourPhotographs(Request $request, TourPackage $package): array
    {
        if (! $request->hasFile('images')) {
            return [];
        }

        // An id boundary rather than an offset: skip() without limit() emits
        // OFFSET with no LIMIT, which SQLite rejects outright.
        $lastId = (int) $package->documents()->max('id');

        $rejected = $this->storeUploadedImages($request, $package, DocumentCategory::TourMedia);

        $sort = (int) $package->media()->max('sort_order');
        $hasCover = $package->media()->where('is_cover', true)->exists();

        foreach ($package->documents()->where('id', '>', $lastId)->get() as $document) {
            $package->media()->create([
                'url' => $document->url(),
                'alt_text' => $package->name,
                'is_cover' => ! $hasCover && $sort === 0,
                'sort_order' => ++$sort,
            ]);

            $hasCover = true;
        }

        return $rejected;
    }
}
