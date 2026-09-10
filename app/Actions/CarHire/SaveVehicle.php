<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Services\AuditLogger;
use App\Support\PublicMediaUrl;
use App\Support\Publishing\VehicleReadiness;
use App\Support\VehicleSpecification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveVehicle
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Create or update a public hire-catalogue vehicle. Omitting `media` on an
     * update preserves the existing collection; an explicit empty array clears
     * it. Catalogue and operational states are deliberately independent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(User $actor, array $attributes, ?Vehicle $vehicle = null): Vehicle
    {
        $this->ensureOperationsActor($actor);
        $validated = $this->validate($attributes, $vehicle);

        return DB::transaction(function () use ($actor, $attributes, $validated, $vehicle): Vehicle {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            // Every car-hire catalogue/rate action locks actor then vehicle. A
            // rate action locks rate rows only after this vehicle lock.
            $lockedVehicle = $vehicle === null
                ? new Vehicle
                : Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();

            $oldValues = $lockedVehicle->exists
                ? $this->auditValues($lockedVehicle) + $this->mediaAuditValues($lockedVehicle)
                : [];

            $catalogueStatus = array_key_exists('catalogue_status', $validated)
                ? ($validated['catalogue_status'] instanceof VehicleCatalogueStatus
                    ? $validated['catalogue_status']
                    : VehicleCatalogueStatus::from($validated['catalogue_status']))
                : $lockedVehicle->catalogue_status;
            $operationalStatus = array_key_exists('operational_status', $validated)
                ? ($validated['operational_status'] instanceof VehicleOperationalStatus
                    ? $validated['operational_status']
                    : VehicleOperationalStatus::from($validated['operational_status']))
                : $lockedVehicle->operational_status;

            if (! $lockedVehicle->exists && $catalogueStatus !== VehicleCatalogueStatus::Draft) {
                $this->invalid('catalogue_status', 'Create the vehicle as a draft before publishing or archiving it.');
            }

            if ($lockedVehicle->exists) {
                $this->assertCatalogueTransition($lockedVehicle->catalogue_status, $catalogueStatus);
            }

            $slug = trim((string) ($validated['slug'] ?? ''));

            if ($slug === '' && $lockedVehicle->exists && filled($lockedVehicle->slug)) {
                // Blank means "leave it alone", not "rewrite my address".
                // Deriving it again from make, model and year would change
                // the public URL of a vehicle that is already listed —
                // every link to it, everywhere, silently broken by an edit
                // that had nothing to do with the slug.
                $slug = (string) $lockedVehicle->slug;
            }

            if ($slug === '') {
                $slug = Str::slug($validated['make'].'-'.$validated['model'].'-'.$validated['year']);
            }

            if ($slug === '') {
                $this->invalid('slug', 'Enter a valid vehicle slug.');
            }

            $slugExists = Vehicle::query()
                ->where('slug', $slug)
                ->when($lockedVehicle->exists, fn ($query) => $query->whereKeyNot($lockedVehicle->getKey()))
                ->exists();

            if ($slugExists) {
                $this->invalid('slug', 'That vehicle slug is already in use.');
            }

            $registrationPlate = mb_strtoupper(
                preg_replace('/\s+/u', ' ', trim($validated['registration_plate'])) ?? '',
            );
            $plateExists = Vehicle::query()
                ->where('registration_plate', $registrationPlate)
                ->when($lockedVehicle->exists, fn ($query) => $query->whereKeyNot($lockedVehicle->getKey()))
                ->exists();

            if ($plateExists) {
                $this->invalid('registration_plate', 'That registration plate is already in use.');
            }

            $lockedVehicle->forceFill([
                'slug' => $slug,
                'registration_plate' => $registrationPlate,
                'make' => trim($validated['make']),
                'model' => trim($validated['model']),
                'year' => (int) $validated['year'],
                'color' => trim($validated['color']),
                'condition' => trim($validated['condition']),
                'vehicle_type' => trim($validated['vehicle_type']),
                'fuel_type' => trim($validated['fuel_type']),
                'transmission' => trim($validated['transmission']),
                // Present-or-absent, so an API caller that omits them does not
                // wipe a specification it never mentioned.
                'drive_type' => array_key_exists('drive_type', $validated)
                    ? $this->nullableString($validated['drive_type'])
                    : $lockedVehicle->drive_type,
                'engine_cc' => array_key_exists('engine_cc', $validated)
                    ? ($validated['engine_cc'] === null ? null : (int) $validated['engine_cc'])
                    : $lockedVehicle->engine_cc,
                'seating_capacity' => (int) $validated['seating_capacity'],
                'luggage_capacity' => (int) ($validated['luggage_capacity'] ?? 0),
                'summary' => trim($validated['summary']),
                // Present-or-absent, not filled-or-empty. The admin form no
                // longer carries a description field, and reading a missing
                // key as null would erase the text on the next save.
                'description' => array_key_exists('description', $validated)
                    ? $this->nullableString($validated['description'])
                    : $lockedVehicle->description,
                'catalogue_status' => $catalogueStatus,
                'operational_status' => $operationalStatus,
                'published_at' => $catalogueStatus === VehicleCatalogueStatus::Published
                    ? ($lockedVehicle->published_at ?? now())
                    : null,
                'is_featured' => array_key_exists('is_featured', $validated)
                    ? (bool) $validated['is_featured']
                    : (bool) ($lockedVehicle->is_featured ?? false),
                'created_by_user_id' => $lockedVehicle->exists
                    ? $lockedVehicle->created_by_user_id
                    : $lockedActor->getKey(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ])->save();

            if (array_key_exists('media', $attributes)) {
                // Lock existing nested rows before replacing the aggregate.
                $lockedVehicle->media()->orderBy('id')->lockForUpdate()->get();
                $lockedVehicle->media()->delete();
                $lockedVehicle->media()->createMany($validated['media'] ?? []);
            }

            $lockedVehicle->load('media');
            $currentRateCount = $this->currentSupportedRates($lockedVehicle)->count();

            if ($catalogueStatus === VehicleCatalogueStatus::Published) {
                $this->assertPublishable($lockedVehicle);
            }

            $this->auditLogger->record(
                event: $vehicle === null ? 'vehicle.created' : 'vehicle.updated',
                auditable: $lockedVehicle,
                oldValues: $oldValues,
                newValues: $this->auditValues($lockedVehicle) + $this->mediaAuditValues($lockedVehicle) + [
                    'current_supported_rate_count' => $currentRateCount,
                ],
                user: $lockedActor,
            );

            return $lockedVehicle->load('hireRates');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validate(array $attributes, ?Vehicle $vehicle): array
    {
        $currentYear = (int) now()->format('Y');
        $catalogueRules = $vehicle === null
            ? ['required', Rule::enum(VehicleCatalogueStatus::class)]
            : ['sometimes', Rule::enum(VehicleCatalogueStatus::class)];
        $operationalRules = $vehicle === null
            ? ['required', Rule::enum(VehicleOperationalStatus::class)]
            : ['sometimes', Rule::enum(VehicleOperationalStatus::class)];

        $validated = Validator::make($attributes, [
            'slug' => [
                'nullable',
                'string',
                'max:200',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('vehicles', 'slug')->ignore($vehicle?->getKey()),
            ],
            'registration_plate' => ['required', 'string', 'max:32'],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1886', 'max:'.($currentYear + 2)],
            'color' => ['required', 'string', 'max:60'],
            /*
             * Closed lists, but the record's own value is always allowed.
             *
             * A vehicle saved before these lists existed holds "good" as its
             * condition. Rejecting that would make an old vehicle unsaveable
             * until somebody noticed which of a dozen fields the form was
             * objecting to — so the current value stays valid until it is
             * deliberately changed. VehicleSpecification::optionsPreserving()
             * puts the same value in the dropdown, so the two agree.
             */
            'condition' => ['required', 'string', 'max:40', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::conditions(), $vehicle?->condition),
            )],
            'vehicle_type' => ['required', 'string', 'max:40', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::bodyTypes(), $vehicle?->vehicle_type),
            )],
            'fuel_type' => ['required', 'string', 'max:40', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::fuelTypes(), $vehicle?->fuel_type),
            )],
            'transmission' => ['required', 'string', 'max:40', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::transmissions(), $vehicle?->transmission),
            )],
            'drive_type' => ['nullable', 'string', 'max:16', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::driveTypes(), $vehicle?->drive_type),
            )],
            'engine_cc' => ['nullable', 'integer', 'min:50', 'max:20000'],
            'seating_capacity' => ['required', 'integer', 'min:1', 'max:65535'],
            'luggage_capacity' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'catalogue_status' => $catalogueRules,
            'operational_status' => $operationalRules,
            'is_featured' => ['sometimes', 'boolean'],
            'media' => ['sometimes', 'array', 'max:20'],
            'media.*.url' => ['required', 'string', 'max:2048'],
            'media.*.alt_text' => ['nullable', 'string', 'max:255'],
            'media.*.caption' => ['nullable', 'string', 'max:500'],
            'media.*.is_cover' => ['sometimes', 'boolean'],
            'media.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ])->validate();

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

        return $validated;
    }

    private function assertCatalogueTransition(
        VehicleCatalogueStatus $from,
        VehicleCatalogueStatus $to,
    ): void {
        if ($from !== $to && ! $from->canTransitionTo($to)) {
            $this->invalid(
                'catalogue_status',
                "A {$from->label()} vehicle cannot move to {$to->label()}.",
            );
        }
    }

    /**
     * The publication gate, in words the person publishing can act on.
     *
     * It used to raise "A published vehicle needs a currently effective
     * supported rate for at least one hire mode" against `catalogue_status` —
     * a field at the top of a form, describing a problem in a rate table on a
     * different screen, using three pieces of jargon in one sentence.
     *
     * VehicleReadiness works out which specific condition failed. Raising every
     * failure at once, each against its own field, is deliberate: being told
     * about the photograph, fixing it, and only then being told about the price
     * is two round trips for one problem.
     */
    private function assertPublishable(Vehicle $vehicle): void
    {
        $readiness = VehicleReadiness::for($vehicle);

        if (! $readiness->isReady()) {
            $readiness->raise();
        }
    }

    /** @return Collection<int, VehicleHireRate> */
    private function currentSupportedRates(Vehicle $vehicle): Collection
    {
        $now = now();

        return VehicleHireRate::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('currency', config('car_hire.currencies', ['UGX', 'USD']))
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $now);
            })
            ->where(function ($query): void {
                $query->where('self_drive_daily_minor', '>', 0)
                    ->orWhere('with_driver_daily_minor', '>', 0);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @return array<string, mixed> */
    private function auditValues(Vehicle $vehicle): array
    {
        return [
            'slug' => $vehicle->slug,
            'registration_plate' => $vehicle->registration_plate,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'vehicle_type' => $vehicle->vehicle_type,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'drive_type' => $vehicle->drive_type,
            'engine_cc' => $vehicle->engine_cc,
            'condition' => $vehicle->condition,
            'catalogue_status' => $vehicle->catalogue_status->value,
            'operational_status' => $vehicle->operational_status->value,
            'seating_capacity' => $vehicle->seating_capacity,
            'luggage_capacity' => $vehicle->luggage_capacity,
            'is_featured' => $vehicle->is_featured,
            'published_at' => $vehicle->published_at?->toIso8601String(),
        ];
    }

    /** @return array{media_count: int, cover_media_count: int} */
    private function mediaAuditValues(Vehicle $vehicle): array
    {
        return [
            'media_count' => $vehicle->media()->count(),
            'cover_media_count' => $vehicle->media()->where('is_cover', true)->count(),
        ];
    }
}
