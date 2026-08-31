<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SaveTourDeparture
{
    use InteractsWithTourDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $actor,
        TourPackage $package,
        array $attributes,
        ?TourDeparture $departure = null,
    ): TourDeparture {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make($attributes, [
            'starts_at' => ['required'],
            'ends_at' => ['required'],
            'cancellation_cutoff_at' => ['nullable'],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_departure_capacity', 500)],
            'price_override' => ['nullable', 'string', 'max:40'],
            'price_override_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => $departure === null
                ? ['required', Rule::enum(TourDepartureStatus::class)]
                : ['sometimes', Rule::enum(TourDepartureStatus::class)],
            'meeting_point' => ['nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $package, $attributes, $validated, $departure): TourDeparture {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedPackage = TourPackage::query()
                ->whereKey($package->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPackage->status === TourPackageStatus::Archived && $departure === null) {
                $this->invalid('tour_package_id', 'Departures cannot be added to an archived package.');
            }

            $lockedDeparture = $departure === null
                ? new TourDeparture
                : TourDeparture::query()->whereKey($departure->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedDeparture->exists && $lockedDeparture->tour_package_id !== $lockedPackage->getKey()) {
                $this->invalid('tour_package_id', 'The departure does not belong to this package.');
            }

            $startsAt = $this->utcDateTime($validated['starts_at'], 'starts_at');
            $endsAt = $this->utcDateTime($validated['ends_at'], 'ends_at');
            $cutoffAt = filled($validated['cancellation_cutoff_at'] ?? null)
                ? $this->utcDateTime($validated['cancellation_cutoff_at'], 'cancellation_cutoff_at')
                : $startsAt->subHours($lockedPackage->cancellation_cutoff_hours);

            if (! $endsAt->isAfter($startsAt)) {
                $this->invalid('ends_at', 'The departure end must be after its start.');
            }

            if (! $cutoffAt->isBefore($startsAt)) {
                $this->invalid('cancellation_cutoff_at', 'The booking and cancellation cutoff must be before departure.');
            }

            $duplicateStart = TourDeparture::query()
                ->where('tour_package_id', $lockedPackage->getKey())
                ->where('starts_at', $startsAt)
                ->when(
                    $lockedDeparture->exists,
                    fn ($query) => $query->whereKeyNot($lockedDeparture->getKey()),
                )
                ->exists();

            if ($duplicateStart) {
                $this->invalid('starts_at', 'This package already has a departure at that start time.');
            }

            $capacity = (int) $validated['capacity'];

            if ($capacity < $lockedPackage->min_travelers) {
                $this->invalid('capacity', "Capacity must be at least {$lockedPackage->min_travelers} for this package.");
            }

            $status = array_key_exists('status', $validated)
                ? ($validated['status'] instanceof TourDepartureStatus
                    ? $validated['status']
                    : TourDepartureStatus::from($validated['status']))
                : $lockedDeparture->status;

            if (! $lockedDeparture->exists
                && in_array($status, [TourDepartureStatus::Cancelled, TourDepartureStatus::Completed], true)) {
                $this->invalid('status', 'A new departure must be scheduled or closed.');
            }

            if ($lockedDeparture->exists) {
                $this->assertStatusTransition($lockedDeparture->status, $status);
            }

            $now = now();
            $becomingScheduled = ! $lockedDeparture->exists
                || ($lockedDeparture->status !== TourDepartureStatus::Scheduled && $status === TourDepartureStatus::Scheduled);

            if ($becomingScheduled && (! $startsAt->isAfter($now) || ! $cutoffAt->isAfter($now))) {
                $this->invalid('starts_at', 'A scheduled departure and its cutoff must both be in the future.');
            }

            $hasBookings = false;
            $reservedSeats = 0;

            if ($lockedDeparture->exists) {
                $hasBookings = $lockedDeparture->bookings()->exists();
                $reservedSeats = (int) $lockedDeparture->bookings()->holdingCapacity()->sum('traveler_count');

                if ($capacity < $reservedSeats) {
                    $this->invalid('capacity', "Capacity cannot be lower than the {$reservedSeats} reserved seat(s).");
                }

                $datesChanged = ! $startsAt->equalTo($lockedDeparture->starts_at)
                    || ! $endsAt->equalTo($lockedDeparture->ends_at)
                    || ! $cutoffAt->equalTo($lockedDeparture->cancellation_cutoff_at);

                if ($hasBookings && $datesChanged) {
                    $this->invalid('starts_at', 'Dates and cutoffs cannot change after a departure has bookings. Create a replacement departure instead.');
                }
            }

            if (in_array($status, [TourDepartureStatus::Cancelled, TourDepartureStatus::Completed], true)
                && $reservedSeats > 0) {
                $this->invalid('status', 'Resolve all capacity-holding bookings before closing this departure permanently.');
            }

            if ($status === TourDepartureStatus::Completed && $endsAt->isAfter($now)) {
                $this->invalid('status', 'A departure cannot be completed before its scheduled end.');
            }

            $hasOverride = (array_key_exists('price_override', $attributes) && filled($attributes['price_override']))
                || (array_key_exists('price_override_minor', $attributes)
                    && $attributes['price_override_minor'] !== null
                    && $attributes['price_override_minor'] !== '');
            $overrideCurrency = $hasOverride
                ? $this->currency($validated['currency'] ?? $lockedPackage->currency)
                : null;
            $priceOverrideMinor = $hasOverride
                ? $this->money(
                    $attributes,
                    majorKey: 'price_override',
                    minorKey: 'price_override_minor',
                    currency: $overrideCurrency,
                )
                : null;
            $oldValues = $lockedDeparture->exists ? $this->auditValues($lockedDeparture) : [];

            $lockedDeparture->forceFill([
                'tour_package_id' => $lockedPackage->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'cancellation_cutoff_at' => $cutoffAt,
                'capacity' => $capacity,
                'price_override_minor' => $priceOverrideMinor,
                'currency' => $overrideCurrency,
                'status' => $status,
                'meeting_point' => $this->nullableString($validated['meeting_point'] ?? null),
                'customer_notes' => $this->nullableString($validated['customer_notes'] ?? null),
                'internal_notes' => $this->nullableString($validated['internal_notes'] ?? null),
            ])->save();

            $this->auditLogger->record(
                event: $departure === null ? 'tour_departure.created' : 'tour_departure.updated',
                auditable: $lockedDeparture,
                oldValues: $oldValues,
                newValues: $this->auditValues($lockedDeparture) + [
                    'reserved_seats' => $reservedSeats,
                    'has_bookings' => $hasBookings,
                ],
                user: $lockedActor,
            );

            return $lockedDeparture->fresh(['tourPackage', 'bookings']);
        }, 3);
    }

    private function assertStatusTransition(TourDepartureStatus $from, TourDepartureStatus $to): void
    {
        $allowed = match ($from) {
            TourDepartureStatus::Scheduled => [
                TourDepartureStatus::Scheduled,
                TourDepartureStatus::Closed,
                TourDepartureStatus::Cancelled,
                TourDepartureStatus::Completed,
            ],
            TourDepartureStatus::Closed => [
                TourDepartureStatus::Closed,
                TourDepartureStatus::Scheduled,
                TourDepartureStatus::Cancelled,
                TourDepartureStatus::Completed,
            ],
            TourDepartureStatus::Cancelled => [TourDepartureStatus::Cancelled],
            TourDepartureStatus::Completed => [TourDepartureStatus::Completed],
        };

        if (! in_array($to, $allowed, true)) {
            $this->invalid('status', "A {$from->label()} departure cannot move to {$to->label()}.");
        }
    }

    /** @return array<string, mixed> */
    private function auditValues(TourDeparture $departure): array
    {
        return [
            'tour_package_id' => $departure->tour_package_id,
            'starts_at' => $departure->starts_at?->toIso8601String(),
            'ends_at' => $departure->ends_at?->toIso8601String(),
            'cancellation_cutoff_at' => $departure->cancellation_cutoff_at?->toIso8601String(),
            'capacity' => $departure->capacity,
            'price_override_minor' => $departure->price_override_minor,
            'currency' => $departure->currency,
            'status' => $departure->status?->value,
        ];
    }
}
