<?php

namespace App\Actions\Accommodation;

use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a room type.
 *
 * The interesting rule is `quantity`: it is the number of rooms that exist, and
 * every availability answer is arithmetic against it. Lowering it below what is
 * already committed would oversell rooms that have been promised, so the action
 * checks the busiest night still ahead before allowing the reduction.
 */
class SaveRoomType
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, Property $property, array $attributes): PropertyRoomType
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $property, $input): PropertyRoomType {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            $lockedProperty = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedProperty->status->isEditable()) {
                throw ValidationException::withMessages([
                    'property' => 'An archived property is read-only. Restore it to a draft first.',
                ]);
            }

            $roomType = new PropertyRoomType;
            $roomType->forceFill(array_merge($input, [
                'property_id' => $lockedProperty->getKey(),
                'slug' => $this->uniqueSlug($lockedProperty, $input['name']),
            ]))->save();

            $this->auditLogger->record(
                event: 'property_room_type.created',
                auditable: $roomType,
                newValues: [
                    'property_id' => $lockedProperty->getKey(),
                    'name' => $roomType->name,
                    'quantity' => $roomType->quantity,
                ],
                user: $lockedActor,
            );

            return $roomType;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, PropertyRoomType $roomType, array $attributes): PropertyRoomType
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $roomType, $input): PropertyRoomType {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            $locked = PropertyRoomType::query()
                ->whereKey($roomType->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertQuantityCoversCommitments($locked, (int) $input['quantity']);

            // Deactivating a room type stops new bookings; it does not cancel
            // the ones already taken, which is why the same commitment check
            // applies as if the quantity had gone to zero.
            if (! $input['is_active'] && $locked->is_active) {
                $this->assertQuantityCoversCommitments($locked, 0);
            }

            $previous = ['quantity' => $locked->quantity, 'is_active' => $locked->is_active];

            $locked->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'property_room_type.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: ['quantity' => $locked->quantity, 'is_active' => $locked->is_active],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * Refuses a reduction that would oversell rooms already promised.
     *
     * Only nights from today onwards are considered: a night in the past is
     * already slept, and blocking an edit because of it would leave the record
     * permanently uneditable.
     */
    private function assertQuantityCoversCommitments(PropertyRoomType $roomType, int $quantity): void
    {
        $furthest = PropertyBooking::query()
            ->where('property_room_type_id', $roomType->getKey())
            ->holdingInventory()
            ->max('check_out_date');

        if ($furthest === null) {
            return;
        }

        $committed = $roomType->committedRoomsByNight(now()->toDateString(), $furthest);

        $busiest = $committed === [] ? 0 : max($committed);

        if ($busiest > $quantity) {
            throw ValidationException::withMessages([
                'quantity' => "There are already {$busiest} rooms committed on at least one night. "
                    .'Cancel or move those bookings before reducing the count.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'max_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_children' => ['required', 'integer', 'min:0', 'max:20'],
            'bed_configuration' => ['nullable', 'string', 'max:120'],
            'size_sqm' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ])->validate();

        return [
            'name' => trim((string) $validated['name']),
            'description' => filled($validated['description'] ?? null)
                ? trim((string) $validated['description'])
                : null,
            'quantity' => (int) $validated['quantity'],
            'max_adults' => (int) $validated['max_adults'],
            'max_children' => (int) $validated['max_children'],
            'bed_configuration' => filled($validated['bed_configuration'] ?? null)
                ? trim((string) $validated['bed_configuration'])
                : null,
            'size_sqm' => $validated['size_sqm'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    private function uniqueSlug(Property $property, string $source): string
    {
        $base = Str::slug($source) ?: 'room';
        $slug = $base;
        $suffix = 1;

        while (PropertyRoomType::query()
            ->where('property_id', $property->getKey())
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
