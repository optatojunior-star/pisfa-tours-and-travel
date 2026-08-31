<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\TourPackageItemType;
use App\Enums\TourPackageStatus;
use App\Models\TourCategory;
use App\Models\TourPackage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PublicMediaUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveTourPackage
{
    use InteractsWithTourDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Create or update a package and any nested collections supplied by the caller.
     * Omitting a nested key while updating preserves that collection; supplying an
     * empty array intentionally clears it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $actor, array $attributes, ?TourPackage $package = null): TourPackage
    {
        $this->ensureOperationsActor($actor);
        $attributes = $this->normalizeNestedInput($attributes);
        $validated = $this->validate($attributes, $package);

        return DB::transaction(function () use ($actor, $attributes, $validated, $package): TourPackage {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $category = TourCategory::query()
                ->whereKey($validated['tour_category_id'])
                ->lockForUpdate()
                ->first();

            if ($category === null) {
                $this->invalid('tour_category_id', 'Select an existing tour category.');
            }

            $lockedPackage = $package === null
                ? new TourPackage
                : TourPackage::query()->whereKey($package->getKey())->lockForUpdate()->firstOrFail();

            $status = array_key_exists('status', $validated)
                ? ($validated['status'] instanceof TourPackageStatus
                    ? $validated['status']
                    : TourPackageStatus::from($validated['status']))
                : $lockedPackage->status;

            if ($lockedPackage->exists) {
                $this->assertStatusTransition($lockedPackage->status, $status);
            }

            if ($status === TourPackageStatus::Published && ! $category->is_active) {
                $this->invalid('tour_category_id', 'A package cannot be published in an inactive category.');
            }

            $currency = $this->currency($validated['currency']);
            $basePriceMinor = $this->money(
                $attributes,
                majorKey: 'base_price',
                minorKey: 'base_price_minor',
                currency: $currency,
            );
            $oldValues = $lockedPackage->exists ? $this->auditValues($lockedPackage) : [];
            $slug = trim((string) ($validated['slug'] ?? ''));

            if ($slug === '') {
                $slug = Str::slug($validated['name']);
            }

            if ($slug === '') {
                $this->invalid('slug', 'Enter a valid package slug.');
            }

            $slugExists = TourPackage::query()
                ->where('slug', $slug)
                ->when($lockedPackage->exists, fn ($query) => $query->whereKeyNot($lockedPackage->getKey()))
                ->exists();

            if ($slugExists) {
                $this->invalid('slug', 'That package slug is already in use.');
            }

            $lockedPackage->forceFill([
                'tour_category_id' => $category->getKey(),
                'name' => trim($validated['name']),
                'slug' => $slug,
                'destination' => $this->nullableString($validated['destination'] ?? null),
                'summary' => trim($validated['summary']),
                'description' => trim($validated['description']),
                'status' => $status,
                'published_at' => $status === TourPackageStatus::Published
                    ? ($lockedPackage->published_at ?? now())
                    : null,
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'duration_days' => (int) $validated['duration_days'],
                'base_price_minor' => $basePriceMinor,
                'currency' => $currency,
                'min_travelers' => (int) $validated['min_travelers'],
                'max_travelers' => (int) $validated['max_travelers'],
                'cancellation_cutoff_hours' => (int) $validated['cancellation_cutoff_hours'],
                'created_by_user_id' => $lockedPackage->exists
                    ? $lockedPackage->created_by_user_id
                    : $lockedActor->getKey(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            if (array_key_exists('media', $attributes)) {
                $lockedPackage->media()->delete();
                $lockedPackage->media()->createMany($validated['media'] ?? []);
            }

            if (array_key_exists('itinerary_days', $attributes)) {
                $lockedPackage->itineraryDays()->delete();
                $lockedPackage->itineraryDays()->createMany($validated['itinerary_days'] ?? []);
            }

            if (array_key_exists('inclusions', $attributes)) {
                $lockedPackage->items()->inclusions()->delete();
                $lockedPackage->items()->createMany(array_map(
                    static fn (array $item): array => $item + ['item_type' => TourPackageItemType::Inclusion],
                    $validated['inclusions'] ?? [],
                ));
            }

            if (array_key_exists('exclusions', $attributes)) {
                $lockedPackage->items()->exclusions()->delete();
                $lockedPackage->items()->createMany(array_map(
                    static fn (array $item): array => $item + ['item_type' => TourPackageItemType::Exclusion],
                    $validated['exclusions'] ?? [],
                ));
            }

            $lockedPackage->load(['category', 'media', 'itineraryDays', 'items']);

            if ($status === TourPackageStatus::Published) {
                $this->assertPublishable($lockedPackage);
            }

            $this->auditLogger->record(
                event: $package === null ? 'tour_package.created' : 'tour_package.updated',
                auditable: $lockedPackage,
                oldValues: $oldValues,
                newValues: $this->auditValues($lockedPackage) + [
                    'media_count' => $lockedPackage->media->count(),
                    'itinerary_day_count' => $lockedPackage->itineraryDays->count(),
                    'inclusion_count' => $lockedPackage->inclusions()->count(),
                    'exclusion_count' => $lockedPackage->exclusions()->count(),
                ],
                user: $lockedActor,
            );

            return $lockedPackage;
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validate(array $attributes, ?TourPackage $package): array
    {
        $statusRules = $package === null
            ? ['required', Rule::enum(TourPackageStatus::class)]
            : ['sometimes', Rule::enum(TourPackageStatus::class)];
        $rules = [
            'tour_category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('tour_packages', 'slug')->ignore($package?->getKey()),
            ],
            'destination' => ['nullable', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:50000'],
            'status' => $statusRules,
            'is_featured' => ['sometimes', 'boolean'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90)],
            'currency' => ['required', 'string', 'size:3'],
            'base_price' => ['nullable', 'string', 'max:40'],
            'base_price_minor' => ['nullable', 'integer', 'min:0'],
            'min_travelers' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_booking_travelers', 50)],
            'max_travelers' => ['required', 'integer', 'gte:min_travelers', 'max:'.config('tours.maximum_booking_travelers', 50)],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'media' => ['sometimes', 'array', 'max:50'],
            'media.*.url' => ['required', 'string', 'max:2048'],
            'media.*.alt_text' => ['nullable', 'string', 'max:255'],
            'media.*.caption' => ['nullable', 'string', 'max:500'],
            'media.*.is_cover' => ['sometimes', 'boolean'],
            'media.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'itinerary_days' => ['sometimes', 'array', 'max:'.config('tours.maximum_duration_days', 90)],
            'itinerary_days.*.day_number' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90), 'distinct'],
            'itinerary_days.*.title' => ['required', 'string', 'max:180'],
            'itinerary_days.*.description' => ['nullable', 'string', 'max:10000'],
            'itinerary_days.*.activities' => ['nullable', 'array', 'max:100'],
            'itinerary_days.*.activities.*' => ['required', 'string', 'max:500'],
            'itinerary_days.*.meals' => ['nullable', 'string', 'max:255'],
            'itinerary_days.*.overnight_location' => ['nullable', 'string', 'max:255'],
            'itinerary_days.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'inclusions' => ['sometimes', 'array', 'max:100'],
            'inclusions.*.content' => ['required', 'string', 'max:500'],
            'inclusions.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'exclusions' => ['sometimes', 'array', 'max:100'],
            'exclusions.*.content' => ['required', 'string', 'max:500'],
            'exclusions.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];

        $validated = Validator::make($attributes, $rules)->validate();
        $covers = 0;

        foreach ($validated['media'] ?? [] as $index => &$medium) {
            if (! PublicMediaUrl::isSafe($medium['url'])) {
                $this->invalid("media.{$index}.url", 'Use an HTTPS URL or an application-relative media path.');
            }

            $medium['url'] = trim($medium['url']);
            $medium['alt_text'] = $this->nullableString($medium['alt_text'] ?? null);
            $medium['caption'] = $this->nullableString($medium['caption'] ?? null);
            $medium['is_cover'] = (bool) ($medium['is_cover'] ?? false);
            $medium['sort_order'] = (int) ($medium['sort_order'] ?? $index);
            $covers += $medium['is_cover'] ? 1 : 0;
        }
        unset($medium);

        if ($covers > 1) {
            $this->invalid('media', 'Select only one cover image.');
        }

        foreach ($validated['itinerary_days'] ?? [] as $index => &$day) {
            $day['title'] = trim($day['title']);
            $day['description'] = $this->nullableString($day['description'] ?? null);
            $day['activities'] = array_values(array_map(
                static fn (string $activity): string => trim($activity),
                $day['activities'] ?? [],
            ));
            $day['meals'] = $this->nullableString($day['meals'] ?? null);
            $day['overnight_location'] = $this->nullableString($day['overnight_location'] ?? null);
            $day['sort_order'] = (int) ($day['sort_order'] ?? $index);
        }
        unset($day);

        foreach (['inclusions', 'exclusions'] as $collection) {
            foreach ($validated[$collection] ?? [] as $index => &$item) {
                $item['content'] = trim($item['content']);
                $item['sort_order'] = (int) ($item['sort_order'] ?? $index);
            }
            unset($item);
        }

        return $validated;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeNestedInput(array $attributes): array
    {
        if (array_key_exists('category_id', $attributes) && ! array_key_exists('tour_category_id', $attributes)) {
            $attributes['tour_category_id'] = $attributes['category_id'];
        }

        if (array_key_exists('itinerary', $attributes) && ! array_key_exists('itinerary_days', $attributes)) {
            $attributes['itinerary_days'] = $attributes['itinerary'];
        }

        if (is_array($attributes['itinerary_days'] ?? null)) {
            foreach ($attributes['itinerary_days'] as &$day) {
                if (is_array($day) && is_string($day['activities'] ?? null)) {
                    $day['activities'] = array_values(array_filter(array_map(
                        static fn (string $activity): string => trim($activity),
                        preg_split('/\R+/u', $day['activities']) ?: [],
                    ), static fn (string $activity): bool => $activity !== ''));
                }
            }
            unset($day);
        }

        foreach (['inclusions', 'exclusions'] as $key) {
            if (! array_key_exists($key, $attributes) || ! is_array($attributes[$key])) {
                continue;
            }

            $normalized = [];

            foreach (array_values($attributes[$key]) as $index => $item) {
                $normalized[] = is_array($item)
                    ? $item
                    : ['content' => $item, 'sort_order' => $index];
            }

            $attributes[$key] = $normalized;
        }

        return $attributes;
    }

    private function assertStatusTransition(TourPackageStatus $from, TourPackageStatus $to): void
    {
        $allowed = match ($from) {
            TourPackageStatus::Draft => [TourPackageStatus::Draft, TourPackageStatus::Published, TourPackageStatus::Archived],
            TourPackageStatus::Published => [TourPackageStatus::Published, TourPackageStatus::Draft, TourPackageStatus::Archived],
            TourPackageStatus::Archived => [TourPackageStatus::Archived, TourPackageStatus::Draft],
        };

        if (! in_array($to, $allowed, true)) {
            $this->invalid('status', "A {$from->label()} package cannot move to {$to->label()}.");
        }
    }

    private function assertPublishable(TourPackage $package): void
    {
        if ($package->media->where('is_cover', true)->count() !== 1) {
            $this->invalid('media', 'A published package must have exactly one cover image.');
        }

        $dayNumbers = $package->itineraryDays->pluck('day_number')->sort()->values()->all();

        if ($dayNumbers !== range(1, $package->duration_days)) {
            $this->invalid('itinerary_days', 'A published package needs one itinerary entry for every day.');
        }

        if ($package->inclusions()->count() === 0 || $package->exclusions()->count() === 0) {
            $this->invalid('inclusions', 'A published package must list both inclusions and exclusions.');
        }
    }

    /** @return array<string, mixed> */
    private function auditValues(TourPackage $package): array
    {
        return [
            'tour_category_id' => $package->tour_category_id,
            'name' => $package->name,
            'slug' => $package->slug,
            'status' => $package->status->value,
            'duration_days' => $package->duration_days,
            'base_price_minor' => $package->base_price_minor,
            'currency' => $package->currency,
            'min_travelers' => $package->min_travelers,
            'max_travelers' => $package->max_travelers,
            'cancellation_cutoff_hours' => $package->cancellation_cutoff_hours,
            'is_featured' => $package->is_featured,
            'published_at' => $package->published_at?->toIso8601String(),
        ];
    }
}
