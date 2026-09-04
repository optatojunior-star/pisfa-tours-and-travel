<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Notifications\CarHire\CarHireBookingReceivedNotification;
use App\Services\AuditLogger;
use App\Support\LegalDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;

class CreateCarHireBooking
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $customer,
        Vehicle $vehicle,
        array $attributes,
        string $idempotencyKey,
    ): CarHireBooking {
        $input = $this->validatedInput($customer, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $vehicle, $input): CarHireBooking {
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $lockedCustomer->email_verified_at === null) {
                throw new AuthorizationException;
            }

            $existing = CarHireBooking::query()
                ->where('customer_id', $lockedCustomer->getKey())
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->vehicle_id !== $vehicle->getKey()
                    || ! hash_equals($existing->request_fingerprint, $input['request_fingerprint'])) {
                    $this->invalid('idempotency_key', 'This idempotency key was already used for a different hire request.');
                }

                return $existing->load(['vehicle.coverMedia', 'hireRate', 'selfDriveApplication', 'contracts']);
            }

            $lockedVehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->first();

            if ($lockedVehicle === null
                || $lockedVehicle->catalogue_status !== VehicleCatalogueStatus::Published
                || $lockedVehicle->published_at === null
                || $lockedVehicle->published_at->isFuture()
                || $lockedVehicle->operational_status !== VehicleOperationalStatus::Available) {
                $this->invalid('vehicle', 'This vehicle is not available for hire.');
            }

            $rate = VehicleHireRate::query()
                ->where('vehicle_id', $lockedVehicle->getKey())
                ->where('currency', $input['currency'])
                ->where('is_active', true)
                ->where('effective_from', '<=', $input['pickup_at'])
                ->where(function (Builder $query) use ($input): void {
                    $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $input['return_at']);
                })
                ->latest('effective_from')
                ->lockForUpdate()
                ->first();

            $dailyRateMinor = match ($input['hire_mode']) {
                HireMode::SelfDrive => $rate?->self_drive_daily_minor,
                HireMode::WithDriver => $rate?->with_driver_daily_minor,
            };

            if ($rate === null || $dailyRateMinor === null || $dailyRateMinor < 1) {
                $this->invalid('hire_mode', 'The selected hire mode and currency are not priced for this entire interval.');
            }

            $now = now();
            $hasConflict = CarHireBooking::query()
                ->where('vehicle_id', $lockedVehicle->getKey())
                ->where(function (Builder $query) use ($now): void {
                    $query->whereIn('status', [
                        CarHireBookingStatus::Confirmed->value,
                        CarHireBookingStatus::InProgress->value,
                    ])->orWhere(function (Builder $pending) use ($now): void {
                        $pending->where('status', CarHireBookingStatus::Pending->value)
                            ->where('hold_expires_at', '>', $now);
                    });
                })
                ->where('pickup_at', '<', $input['return_at'])
                ->where('return_at', '>', $input['pickup_at'])
                ->exists();

            if ($hasConflict) {
                $this->invalid('pickup_at', 'This vehicle is no longer available for the selected interval.');
            }

            if ($dailyRateMinor > intdiv(PHP_INT_MAX, $input['billable_days'])) {
                $this->invalid('return_at', 'The rental total is too large.');
            }

            $subtotalMinor = $dailyRateMinor * $input['billable_days'];
            $depositMinor = $rate->security_deposit_minor;

            if ($depositMinor > PHP_INT_MAX - $subtotalMinor) {
                $this->invalid('return_at', 'The rental total is too large.');
            }

            $totalMinor = $subtotalMinor + $depositMinor;
            $holdExpiresAt = CarbonImmutable::instance($now)
                ->addMinutes((int) config('car_hire.pending_hold_minutes', 1440));

            if ($holdExpiresAt->isAfter($input['pickup_at'])) {
                $holdExpiresAt = $input['pickup_at'];
            }

            $booking = CarHireBooking::query()->create([
                'reference' => 'HIRE-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer->getKey(),
                'vehicle_id' => $lockedVehicle->getKey(),
                'vehicle_hire_rate_id' => $rate->getKey(),
                'idempotency_key' => $input['idempotency_key'],
                'request_fingerprint' => $input['request_fingerprint'],
                'status' => CarHireBookingStatus::Pending,
                'hire_mode' => $input['hire_mode'],
                'pickup_at' => $input['pickup_at'],
                'return_at' => $input['return_at'],
                'cancellation_cutoff_at' => $input['pickup_at']->subHours(
                    (int) config('car_hire.default_cancellation_cutoff_hours', 48),
                ),
                'hold_expires_at' => $holdExpiresAt,
                'billable_days' => $input['billable_days'],
                'vehicle_name_snapshot' => trim($lockedVehicle->year.' '.$lockedVehicle->make.' '.$lockedVehicle->model),
                'registration_plate_snapshot' => $lockedVehicle->registration_plate,
                'daily_rate_minor' => $dailyRateMinor,
                'rental_subtotal_minor' => $subtotalMinor,
                'security_deposit_minor' => $depositMinor,
                'total_minor' => $totalMinor,
                'currency' => $rate->currency,
                'contact_name' => $lockedCustomer->name,
                'contact_email' => mb_strtolower($lockedCustomer->email),
                'contact_phone' => $input['contact_phone'],
                'pickup_location' => $input['pickup_location'],
                'return_location' => $input['return_location'],
                'special_requests' => $input['special_requests'],
            ]);

            if ($input['hire_mode'] === HireMode::SelfDrive) {
                $booking->selfDriveApplication()->create([
                    'status' => SelfDriveApplicationStatus::Draft,
                ]);
            }

            $this->issueInitialContract($booking);

            $this->auditLogger->record(
                event: 'car_hire_booking.created',
                auditable: $booking,
                newValues: [
                    'reference' => $booking->reference,
                    'vehicle_id' => $lockedVehicle->getKey(),
                    'vehicle_hire_rate_id' => $rate->getKey(),
                    'status' => CarHireBookingStatus::Pending->value,
                    'hire_mode' => $input['hire_mode']->value,
                    'pickup_at' => $input['pickup_at']->toIso8601String(),
                    'return_at' => $input['return_at']->toIso8601String(),
                    'billable_days' => $input['billable_days'],
                    'daily_rate_minor' => $dailyRateMinor,
                    'security_deposit_minor' => $depositMinor,
                    'total_minor' => $totalMinor,
                    'currency' => $rate->currency,
                    'hold_expires_at' => $holdExpiresAt->toIso8601String(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(function () use ($lockedCustomer, $booking): void {
                $lockedCustomer->notify(new CarHireBookingReceivedNotification(
                    bookingReference: $booking->reference,
                    vehicleName: $booking->vehicle_name_snapshot,
                    pickupAt: $booking->pickup_at->toIso8601String(),
                    totalMinor: $booking->total_minor,
                    currency: $booking->currency,
                ));
            });

            return $booking->load(['vehicle.coverMedia', 'hireRate', 'selfDriveApplication', 'contracts']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(User $customer, array $attributes, string $idempotencyKey): array
    {
        $attributes['idempotency_key'] = trim($idempotencyKey);
        $validated = Validator::make($attributes, [
            'hire_mode' => ['required', Rule::enum(HireMode::class)],
            'pickup_at' => ['required'],
            'return_at' => ['required'],
            'currency' => ['required', 'string', 'size:3'],
            'pickup_location' => ['required', 'string', 'max:500'],
            'return_location' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'acknowledge_request' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $validated['hire_mode'] = $validated['hire_mode'] instanceof HireMode
            ? $validated['hire_mode']
            : HireMode::from($validated['hire_mode']);
        $validated['pickup_at'] = $this->utcDateTime($validated['pickup_at'], 'pickup_at');
        $validated['return_at'] = $this->utcDateTime($validated['return_at'], 'return_at');
        $validated['currency'] = $this->currency($validated['currency']);
        $validated['pickup_location'] = trim($validated['pickup_location']);
        $validated['return_location'] = $this->nullableString($validated['return_location'] ?? null)
            ?? $validated['pickup_location'];
        $validated['contact_phone'] = trim($validated['contact_phone']);
        $validated['special_requests'] = $this->nullableString($validated['special_requests'] ?? null);

        $now = now();
        $minimumPickup = $now->copy()->addHours((int) config('car_hire.minimum_notice_hours', 2));

        if (! $validated['pickup_at']->isFuture() || $validated['pickup_at']->isBefore($minimumPickup)) {
            $this->invalid('pickup_at', 'Select a future pickup time that meets the minimum notice period.');
        }

        if (! $validated['return_at']->isAfter($validated['pickup_at'])) {
            $this->invalid('return_at', 'The return time must be after pickup.');
        }

        $durationSeconds = $validated['return_at']->getTimestamp() - $validated['pickup_at']->getTimestamp();
        $validated['billable_days'] = max(1, intdiv($durationSeconds + 86399, 86400));

        if ($validated['billable_days'] > (int) config('car_hire.maximum_hire_days', 90)) {
            $this->invalid('return_at', 'The hire period exceeds the maximum supported duration.');
        }

        $fingerprintData = [
            'vehicle_customer_id' => $customer->getKey(),
            'hire_mode' => $validated['hire_mode']->value,
            'pickup_at' => $validated['pickup_at']->toIso8601String(),
            'return_at' => $validated['return_at']->toIso8601String(),
            'currency' => $validated['currency'],
            'pickup_location' => $validated['pickup_location'],
            'return_location' => $validated['return_location'],
            'contact_phone' => $validated['contact_phone'],
            'special_requests' => $validated['special_requests'],
        ];

        try {
            $validated['request_fingerprint'] = hash(
                'sha256',
                json_encode($fingerprintData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );
        } catch (JsonException) {
            $this->invalid('idempotency_key', 'The hire request could not be normalized.');
        }

        return $validated;
    }

    private function issueInitialContract(CarHireBooking $booking): void
    {
        $snapshot = [
            'schema_version' => 1,
            'booking_reference' => $booking->reference,
            'customer' => [
                'name' => $booking->contact_name,
                'email' => $booking->contact_email,
                'phone' => $booking->contact_phone,
            ],
            'vehicle' => [
                'name' => $booking->vehicle_name_snapshot,
                'registration_plate' => $booking->registration_plate_snapshot,
            ],
            'hire_mode' => $booking->hire_mode->value,
            'pickup_at' => $booking->pickup_at->toIso8601String(),
            'return_at' => $booking->return_at->toIso8601String(),
            'pickup_location' => $booking->pickup_location,
            'return_location' => $booking->return_location,
            'billable_days' => $booking->billable_days,
            'daily_rate_minor' => $booking->daily_rate_minor,
            'rental_subtotal_minor' => $booking->rental_subtotal_minor,
            'security_deposit_minor' => $booking->security_deposit_minor,
            'total_minor' => $booking->total_minor,
            'currency' => $booking->currency,
        ];
        $terms = $this->contractTerms();

        $booking->contracts()->create([
            'contract_number' => 'HC-'.Str::upper((string) Str::ulid()),
            'version' => 1,
            'template_version' => (string) config('car_hire.contract.version', '2026-08-20'),
            'snapshot' => $snapshot,
            'terms_snapshot' => $terms,
            'content_sha256' => $this->contractContentHash($snapshot, $terms),
            'issued_at' => now(),
        ]);
    }

    /**
     * The clauses stamped onto this contract.
     *
     * Read from LegalDocuments, which is also what the public car-hire terms
     * page renders, so a customer signs the terms the website showed them.
     *
     * This method used to hold its own short summary ending "insurance scope,
     * damage responsibility, cancellation charges ... require the approved
     * PISFA policy supplied before handover; this agreement does not invent or
     * override that policy" — an honest admission that the contract deferred to
     * a document nobody had written. It exists now, so the contract can say
     * what it means.
     *
     * The text is snapshotted onto the contract row and hashed, so changing the
     * policy later never rewrites an agreement somebody has already accepted.
     */
    private function contractTerms(): string
    {
        return implode("\n\n", [
            'This agreement records the vehicle, hire interval, mode, locations and exact price shown above, and incorporates the PISFA car hire terms set out below.',
            // Said outright, because a document with a total on it reads
            // like a receipt. Booking takes no money: the rental charge and
            // the deposit are collected separately, and a customer must not
            // arrive at handover believing they have already paid.
            'Accepting this agreement does not collect payment. The rental charge and the security deposit are collected separately, and nothing in this document confirms that any money has been received.',
            ...LegalDocuments::rentalAgreementClauses(),
            'Contact PISFA before accepting if any booking, price, vehicle, date, location or term is incorrect.',
        ]);
    }
}
