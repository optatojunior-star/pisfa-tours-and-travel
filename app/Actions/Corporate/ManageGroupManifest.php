<?php

namespace App\Actions\Corporate;

use App\Enums\TourTravelerType;
use App\Models\GroupBooking;
use App\Models\GroupTraveler;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The list of who is actually coming.
 *
 * The manifest is capped at the headcount that was booked and priced. Letting a
 * forty-seat coach take a forty-first name is not generosity — it is somebody
 * standing at the roadside, and it is discovered on the morning of the trip.
 */
class ManageGroupManifest
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function add(User $actor, GroupBooking $booking, array $attributes): GroupTraveler
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $booking, $input): GroupTraveler {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = GroupBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertMayEdit($lockedActor, $locked);

            if (! $locked->status->manifestIsEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'The traveller list is closed for a '
                        .mb_strtolower($locked->status->label()).' group.',
                ]);
            }

            // Counted under the lock: two people filling the list in at once
            // must not both get the last seat.
            if ($locked->manifestCount() >= $locked->headcount) {
                throw ValidationException::withMessages([
                    'full_name' => 'The list is full at '.$locked->headcount
                        .'. Raise the headcount first if the group has grown.',
                ]);
            }

            $traveler = new GroupTraveler;
            $traveler->forceFill(array_merge($input, [
                'group_booking_id' => $locked->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'group_traveler.added',
                auditable: $locked,
                newValues: [
                    'reference' => $locked->reference,
                    // The name goes in; the identity document deliberately does
                    // not — the audit trail is read by more people than the
                    // manifest is.
                    'traveler' => $traveler->full_name,
                    'manifest_count' => $locked->manifestCount(),
                    'headcount' => $locked->headcount,
                ],
                user: $lockedActor,
            );

            return $traveler->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, GroupBooking $booking, GroupTraveler $traveler, array $attributes): GroupTraveler
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $booking, $traveler, $input): GroupTraveler {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = GroupBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertMayEdit($lockedActor, $locked);

            $lockedTraveler = GroupTraveler::query()
                ->whereKey($traveler->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTraveler->group_booking_id !== (int) $locked->getKey()) {
                throw ValidationException::withMessages([
                    'traveler' => 'That traveller is on a different group.',
                ]);
            }

            if (! $locked->status->manifestIsEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'The traveller list is closed for a '
                        .mb_strtolower($locked->status->label()).' group.',
                ]);
            }

            $lockedTraveler->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'group_traveler.updated',
                auditable: $locked,
                newValues: [
                    'reference' => $locked->reference,
                    'traveler' => $lockedTraveler->full_name,
                ],
                user: $lockedActor,
            );

            return $lockedTraveler->fresh();
        }, 3);
    }

    public function remove(User $actor, GroupBooking $booking, GroupTraveler $traveler): void
    {
        DB::transaction(function () use ($actor, $booking, $traveler): void {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = GroupBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertMayEdit($lockedActor, $locked);

            if (! $locked->status->manifestIsEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'The traveller list is closed for a '
                        .mb_strtolower($locked->status->label()).' group.',
                ]);
            }

            $lockedTraveler = GroupTraveler::query()
                ->whereKey($traveler->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTraveler->group_booking_id !== (int) $locked->getKey()) {
                throw ValidationException::withMessages([
                    'traveler' => 'That traveller is on a different group.',
                ]);
            }

            $this->auditLogger->record(
                event: 'group_traveler.removed',
                auditable: $locked,
                oldValues: ['traveler' => $lockedTraveler->full_name],
                newValues: ['reference' => $locked->reference],
                user: $lockedActor,
            );

            $lockedTraveler->delete();
        }, 3);
    }

    private function assertMayEdit(User $actor, GroupBooking $booking): void
    {
        if (CorporateAccess::canManage($actor)) {
            return;
        }

        if ((int) $booking->organiser_id === (int) $actor->getKey()) {
            return;
        }

        $booking->loadMissing('account');

        if ($booking->account !== null
            && (CorporateAccess::membership($actor, $booking->account)?->canBook() ?? false)) {
            return;
        }

        throw new AuthorizationException;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'full_name' => ['required', 'string', 'min:2', 'max:180'],
            'traveler_type' => ['required', Rule::enum(TourTravelerType::class)],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'identity_document' => ['nullable', 'string', 'max:60'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'dietary_requirements' => ['nullable', 'string', 'max:255'],
            'accessibility_needs' => ['nullable', 'string', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:180'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return [
            'full_name' => trim((string) $validated['full_name']),
            'traveler_type' => TourTravelerType::from((string) $validated['traveler_type']),
            'contact_phone' => $this->nullable($validated['contact_phone'] ?? null),
            'contact_email' => $this->nullable($validated['contact_email'] ?? null),
            'identity_document' => $this->nullable($validated['identity_document'] ?? null),
            'date_of_birth' => $this->nullable($validated['date_of_birth'] ?? null),
            'nationality' => $this->nullable($validated['nationality'] ?? null),
            'dietary_requirements' => $this->nullable($validated['dietary_requirements'] ?? null),
            'accessibility_needs' => $this->nullable($validated['accessibility_needs'] ?? null),
            'emergency_contact_name' => $this->nullable($validated['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => $this->nullable($validated['emergency_contact_phone'] ?? null),
            'notes' => $this->nullable($validated['notes'] ?? null),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
