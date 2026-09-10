<?php

namespace App\Actions\Sales;

use App\Enums\ListingStatus;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\VehicleSpecification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or edits a showroom listing.
 *
 * Specification is copied onto the listing rather than read through the fleet
 * vehicle: a listing must stay truthful about what was advertised even after
 * the vehicle is sold, retired, or edited, and stock that was never in the
 * fleet has to be a complete record on its own.
 */
class SaveVehicleListing
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, ?Vehicle $vehicle = null): VehicleListing
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $input, $vehicle): VehicleListing {
            $lockedActor = SalesAccess::lockedManager($actor);

            $lockedVehicle = null;

            if ($vehicle !== null) {
                $lockedVehicle = Vehicle::query()
                    ->whereKey($vehicle->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertVehicleIsSellable($lockedVehicle);
            }

            $listing = new VehicleListing;
            $listing->forceFill(array_merge($input, [
                'reference' => 'LST-'.Str::upper((string) Str::ulid()),
                'slug' => $this->uniqueSlug($input['title']),
                'vehicle_id' => $lockedVehicle?->getKey(),
                'status' => ListingStatus::Draft,
                'created_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'vehicle_listing.created',
                auditable: $listing,
                newValues: [
                    'reference' => $listing->reference,
                    'vehicle_id' => $listing->vehicle_id,
                    'asking_price_minor' => $listing->asking_price_minor,
                    'currency' => $listing->currency,
                ],
                user: $lockedActor,
            );

            return $listing->fresh('vehicle');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, VehicleListing $listing, array $attributes): VehicleListing
    {
        $input = $this->validated($attributes, $listing);

        return DB::transaction(function () use ($actor, $listing, $input): VehicleListing {
            $lockedActor = SalesAccess::lockedManager($actor);

            $locked = VehicleListing::query()
                ->whereKey($listing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // A sold listing is the record of what was advertised and what it
            // fetched. Editing it after the fact would rewrite history.
            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'A '.mb_strtolower($locked->status->label())
                        .' listing cannot be edited.',
                ]);
            }

            $previous = [
                'asking_price_minor' => $locked->asking_price_minor,
                'currency' => $locked->currency,
            ];

            $locked->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'vehicle_listing.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'asking_price_minor' => $locked->asking_price_minor,
                    'currency' => $locked->currency,
                ],
                user: $lockedActor,
            );

            return $locked->fresh('vehicle');
        }, 3);
    }

    /**
     * A fleet vehicle may be listed once at a time, and only when it is not
     * earning.
     *
     * Listing a car that is out on hire, or already in the showroom, would
     * advertise something PISFA cannot deliver.
     */
    private function assertVehicleIsSellable(Vehicle $vehicle): void
    {
        $alreadyListed = VehicleListing::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->inStock()
            ->exists();

        if ($alreadyListed) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'This vehicle is already listed in the showroom.',
            ]);
        }

        $onHire = CarHireBooking::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->holdingVehicle()
            ->where('return_at', '>=', now())
            ->exists();

        if ($onHire) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'This vehicle has hire bookings that have not finished. '
                    .'Complete or cancel them before selling it.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes, ?VehicleListing $listing = null): array
    {
        $currentYear = (int) now()->format('Y');

        $validated = Validator::make($attributes, [
            'title' => ['required', 'string', 'min:4', 'max:200'],
            'make' => ['required', 'string', 'min:2', 'max:60'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'year' => ['required', 'integer', 'min:1950', 'max:'.($currentYear + 1)],
            /*
             * The same closed lists the hire fleet uses.
             *
             * These were free text on both sides, and the two sides disagreed:
             * the showroom stored "Diesel" and "Automatic" while the fleet
             * stored "diesel" and "automatic", so a vehicle moved from hire to
             * sale changed its own specification on the way. The listing keeps
             * whatever it already held, so older stock stays editable.
             */
            'body_type' => ['nullable', 'string', 'max:32', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::bodyTypes(), $listing?->body_type),
            )],
            'fuel_type' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::fuelTypes(), $listing?->fuel_type),
            )],
            'transmission' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::transmissions(), $listing?->transmission),
            )],
            'drive_type' => ['nullable', 'string', 'max:16', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::driveTypes(), $listing?->drive_type),
            )],
            'engine_cc' => ['nullable', 'integer', 'min:50', 'max:20000'],
            'colour' => ['nullable', 'string', 'max:40'],
            'mileage_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'seating_capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'condition' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::conditions(), $listing?->condition),
            )],
            'description' => ['required', 'string', 'min:30', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'asking_price' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'is_negotiable' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);

        try {
            $price = Money::parse((string) $validated['asking_price'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['asking_price' => $exception->getMessage()]);
        }

        // A free car is not a listing; it is a data-entry mistake.
        if ($price < 1) {
            throw ValidationException::withMessages([
                'asking_price' => 'Enter the price the vehicle is offered at.',
            ]);
        }

        return [
            'title' => trim((string) $validated['title']),
            'make' => trim((string) $validated['make']),
            'model' => trim((string) $validated['model']),
            'year' => (int) $validated['year'],
            'body_type' => $this->nullable($validated['body_type'] ?? null),
            'fuel_type' => $this->nullable($validated['fuel_type'] ?? null),
            'transmission' => $this->nullable($validated['transmission'] ?? null),
            'drive_type' => $this->nullable($validated['drive_type'] ?? null),
            'engine_cc' => isset($validated['engine_cc']) ? (int) $validated['engine_cc'] : null,
            'colour' => $this->nullable($validated['colour'] ?? null),
            'mileage_km' => $validated['mileage_km'] ?? null,
            'seating_capacity' => $validated['seating_capacity'] ?? null,
            'condition' => $this->nullable($validated['condition'] ?? null),
            'description' => trim((string) $validated['description']),
            'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
            'asking_price_minor' => $price,
            'currency' => $currency,
            'is_negotiable' => (bool) ($validated['is_negotiable'] ?? false),
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
        ];
    }

    /** Soft-deleted listings are counted, so a withdrawn URL is not reused. */
    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'listing';
        $slug = $base;
        $suffix = 1;

        while (VehicleListing::query()->withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Prefills a listing form from a fleet vehicle. */
    public static function prefillFrom(Vehicle $vehicle): array
    {
        return [
            'title' => trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model),
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            // body_type, engine and drive were silently dropped here, so a
            // retiring fleet vehicle arrived in the showroom with three of its
            // specifications blank and somebody had to retype them. They carry
            // across now that both sides use one vocabulary.
            'body_type' => $vehicle->vehicle_type,
            'engine_cc' => $vehicle->engine_cc,
            'drive_type' => $vehicle->drive_type,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'colour' => $vehicle->color,
            'mileage_km' => $vehicle->current_odometer_km,
            'seating_capacity' => $vehicle->seating_capacity,
            'condition' => $vehicle->condition,
        ];
    }
}
