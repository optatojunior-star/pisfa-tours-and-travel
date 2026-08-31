<?php

namespace App\Actions\Corporate;

use App\Enums\AccountStatus;
use App\Enums\GroupBookingStatus;
use App\Enums\UserRole;
use App\Models\CorporateAccount;
use App\Models\GroupBooking;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\ServiceCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Raises a group trip.
 *
 * A group may be billed to a company or to nobody: a school outing or a family
 * reunion is a group without being a corporate account, and requiring one would
 * turn that enquiry away.
 */
class SaveGroupBooking
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, ?CorporateAccount $account = null): GroupBooking
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $input, $account): GroupBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $lockedAccount = null;

            if ($account !== null) {
                $lockedAccount = CorporateAccount::query()
                    ->whereKey($account->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Authority comes from a live membership on a trading account,
                // re-read under the lock rather than trusted from the screen.
                if (! CorporateAccess::canBookFor($lockedActor, $lockedAccount)
                    && ! CorporateAccess::canManage($lockedActor)) {
                    throw new AuthorizationException;
                }

                if (! $lockedAccount->status->canTrade()) {
                    throw ValidationException::withMessages([
                        'corporate_account_id' => 'This account is '
                            .mb_strtolower($lockedAccount->status->label())
                            .' and cannot take new bookings.',
                    ]);
                }
            } elseif ($lockedActor->status !== AccountStatus::Active
                || ! $lockedActor->hasAnyRole(UserRole::Customer, UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin)) {
                throw new AuthorizationException;
            }

            $booking = new GroupBooking;
            $booking->forceFill(array_merge($input, [
                'reference' => 'GRP-'.Str::upper((string) Str::ulid()),
                'corporate_account_id' => $lockedAccount?->getKey(),
                'organiser_id' => $lockedActor->getKey(),
                'status' => GroupBookingStatus::Enquiry,
            ]))->save();

            $this->auditLogger->record(
                event: 'group_booking.created',
                auditable: $booking,
                newValues: [
                    'reference' => $booking->reference,
                    'corporate_account_id' => $booking->corporate_account_id,
                    'service_kind' => $booking->service_kind,
                    'headcount' => $booking->headcount,
                    'starts_on' => $booking->starts_on->toDateString(),
                    'currency' => $booking->currency,
                ],
                user: $lockedActor,
            );

            return $booking->fresh(['account', 'organiser']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, GroupBooking $booking, array $attributes): GroupBooking
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $booking, $input): GroupBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = GroupBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertMayEdit($lockedActor, $locked);

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => 'A '.mb_strtolower($locked->status->label()).' group cannot be changed.',
                ]);
            }

            // Cutting the headcount below the manifest would leave named people
            // on a trip with no place booked for them.
            if ($input['headcount'] < $locked->manifestCount()) {
                throw ValidationException::withMessages([
                    'headcount' => 'There are already '.$locked->manifestCount()
                        .' names on the list. Remove some before reducing the headcount.',
                ]);
            }

            $previous = [
                'headcount' => $locked->headcount,
                'starts_on' => $locked->starts_on->toDateString(),
            ];

            $locked->forceFill($input)->save();

            $this->auditLogger->record(
                event: 'group_booking.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'headcount' => $locked->headcount,
                    'starts_on' => $locked->starts_on->toDateString(),
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['account', 'organiser']);
        }, 3);
    }

    /** The organiser, an account administrator, or PISFA staff. */
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
            && (CorporateAccess::membership($actor, $booking->account)?->canApprove() ?? false)) {
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
            'title' => ['required', 'string', 'min:4', 'max:200'],
            'service_kind' => ['required', Rule::in(ServiceCatalogue::keys())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'headcount' => ['required', 'integer', 'min:2', 'max:500'],
            'pickup_location' => ['nullable', 'string', 'max:500'],
            'destination' => ['nullable', 'string', 'max:500'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'quoted_total' => ['nullable', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
        ], [
            'headcount.min' => 'A group is at least two people — one traveller is an ordinary booking.',
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);
        $startsOn = CarbonImmutable::parse((string) $validated['starts_on'])->startOfDay();
        $endsOn = CarbonImmutable::parse((string) $validated['ends_on'])->startOfDay();

        // Business dates, so "today" is today in Kampala rather than in UTC.
        $today = CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'))->startOfDay();

        if ($startsOn->isBefore($today)) {
            throw ValidationException::withMessages([
                'starts_on' => 'A trip cannot start in the past.',
            ]);
        }

        $quotedMinor = null;

        if (filled($validated['quoted_total'] ?? null)) {
            try {
                $quotedMinor = Money::parse((string) $validated['quoted_total'], $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['quoted_total' => $exception->getMessage()]);
            }
        }

        return [
            'title' => trim((string) $validated['title']),
            'service_kind' => (string) $validated['service_kind'],
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $endsOn->toDateString(),
            'headcount' => (int) $validated['headcount'],
            'pickup_location' => $this->nullable($validated['pickup_location'] ?? null),
            'destination' => $this->nullable($validated['destination'] ?? null),
            'requirements' => $this->nullable($validated['requirements'] ?? null),
            'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
            'quoted_total_minor' => $quotedMinor,
            'currency' => $currency,
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
