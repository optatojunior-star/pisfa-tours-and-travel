<?php

namespace App\Actions\Accommodation;

use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Prices a room type for a season.
 *
 * Manager-only: a wrong nightly rate is charged to every guest who books before
 * somebody notices, and the booking keeps the rate row it was priced from, so
 * the mistake is not correctable by editing the price afterwards.
 */
class SaveRoomRate
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, PropertyRoomType $roomType, array $attributes): PropertyRoomRate
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $roomType, $input): PropertyRoomRate {
            $lockedActor = AccommodationAccess::lockedPublisher($actor);

            $lockedType = PropertyRoomType::query()
                ->whereKey($roomType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoOverlap($lockedType, $input);

            $rate = new PropertyRoomRate;
            $rate->forceFill(array_merge($input, [
                'property_room_type_id' => $lockedType->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'property_room_rate.created',
                auditable: $rate,
                newValues: [
                    'property_room_type_id' => $lockedType->getKey(),
                    'currency' => $rate->currency,
                    'nightly_rate_minor' => $rate->nightly_rate_minor,
                    'effective_from' => $rate->effective_from->toDateString(),
                    'effective_until' => $rate->effective_until?->toDateString(),
                ],
                user: $lockedActor,
            );

            return $rate;
        }, 3);
    }

    /**
     * Retires a rate without deleting it.
     *
     * Deleting would orphan the `property_room_rate_id` on every booking priced
     * from it, and that link is how a disputed charge is explained a year later.
     */
    public function deactivate(User $actor, PropertyRoomRate $rate): PropertyRoomRate
    {
        return DB::transaction(function () use ($actor, $rate): PropertyRoomRate {
            $lockedActor = AccommodationAccess::lockedPublisher($actor);

            $locked = PropertyRoomRate::query()
                ->whereKey($rate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->auditLogger->record(
                event: 'property_room_rate.deactivated',
                auditable: $locked,
                oldValues: ['is_active' => true],
                newValues: ['is_active' => false],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * Two active rates in one currency may not cover the same night.
     *
     * If they did, `rateFor()` would have to pick one, and whichever it picked
     * would be arbitrary — a guest's price would depend on insert order.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertNoOverlap(PropertyRoomType $roomType, array $input): void
    {
        $from = $input['effective_from'];
        $until = $input['effective_until'];

        // whereDate, so a season that starts exactly where another ends is still
        // seen as the overlap it is — both would cover that day.
        $clashes = PropertyRoomRate::query()
            ->where('property_room_type_id', $roomType->getKey())
            ->where('currency', $input['currency'])
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $until?->toDateString() ?? '9999-12-31')
            ->where(function (Builder $query) use ($from): void {
                $query->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $from->toDateString());
            })
            ->exists();

        if ($clashes) {
            throw ValidationException::withMessages([
                'effective_from' => 'Another active rate in this currency already covers part of that period. '
                    .'Retire it first, or choose dates that do not overlap.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{currency: string, nightly_rate_minor: int, effective_from: CarbonImmutable, effective_until: CarbonImmutable|null, minimum_nights: int, is_active: bool}
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'nightly_rate' => ['required', 'string', 'max:24'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'minimum_nights' => ['required', 'integer', 'min:1', 'max:60'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);

        try {
            $minor = Money::parse((string) $validated['nightly_rate'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['nightly_rate' => $exception->getMessage()]);
        }

        if ($minor < 1) {
            throw ValidationException::withMessages([
                'nightly_rate' => 'Enter what a night actually costs.',
            ]);
        }

        return [
            'currency' => $currency,
            'nightly_rate_minor' => $minor,
            'effective_from' => CarbonImmutable::parse((string) $validated['effective_from'])->startOfDay(),
            'effective_until' => filled($validated['effective_until'] ?? null)
                ? CarbonImmutable::parse((string) $validated['effective_until'])->startOfDay()
                : null,
            'minimum_nights' => (int) $validated['minimum_nights'],
            'is_active' => true,
        ];
    }
}
