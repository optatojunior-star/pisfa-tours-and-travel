<?php

namespace App\Actions\Leasing;

use App\Enums\AccountStatus;
use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutModel;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\VehicleLeaseApplication;
use App\Notifications\Leasing\LeaseApplicationReceivedNotification;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * An owner offers their vehicle to the hire fleet.
 *
 * Guests are first-class: `owner_id` stays null rather than pointing at a
 * placeholder account, and deduplication is keyed to the applicant *and* the
 * registration plate, so one person offering two cars is two applications while
 * a refreshed form is one.
 */
class SubmitLeaseApplication
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(?User $owner, array $attributes, string $idempotencyKey): VehicleLeaseApplication
    {
        $input = $this->validated($owner, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($owner, $input): VehicleLeaseApplication {
            $lockedOwner = null;

            if ($owner !== null) {
                $lockedOwner = User::query()
                    ->whereKey($owner->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOwner->status !== AccountStatus::Active
                    || ! $lockedOwner->hasRole(UserRole::Customer)) {
                    throw new AuthorizationException;
                }
            }

            $existing = VehicleLeaseApplication::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $application = new VehicleLeaseApplication;
            $application->forceFill(array_merge($input['attributes'], [
                'reference' => 'LEASE-'.Str::upper((string) Str::ulid()),
                'owner_id' => $lockedOwner?->getKey(),
                'status' => LeaseApplicationStatus::Submitted,
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ]))->save();

            $this->auditLogger->record(
                event: 'lease_application.created',
                auditable: $application,
                newValues: [
                    'reference' => $application->reference,
                    'guest_application' => $application->isGuest(),
                    'vehicle' => $application->vehicleLabel(),
                    // The plate itself is not written to the audit trail: it
                    // identifies somebody's property and the trail is read by
                    // more people than the application is.
                    'expectation_stated' => $application->expected_monthly_minor !== null,
                ],
                user: $lockedOwner,
            );

            DB::afterCommit(fn () => $this->notify($application));

            return $application;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, idempotency_owner_hash: string, idempotency_key: string}
     */
    private function validated(?User $owner, array $attributes, string $idempotencyKey): array
    {
        if ($owner !== null) {
            // A signed-in owner never supplies their own identity.
            $attributes = array_merge($attributes, [
                'contact_name' => $owner->name,
                'contact_email' => $owner->email,
                'contact_phone' => $attributes['contact_phone'] ?? $owner->phone,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);
        $currentYear = (int) now()->format('Y');

        $validated = Validator::make($attributes, [
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'make' => ['required', 'string', 'min:2', 'max:60'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'year' => ['required', 'integer', 'min:1990', 'max:'.($currentYear + 1)],
            'registration_plate' => ['required', 'string', 'min:4', 'max:32'],
            'colour' => ['nullable', 'string', 'max:40'],
            'transmission' => ['nullable', 'string', 'max:24'],
            'fuel_type' => ['nullable', 'string', 'max:24'],
            'seating_capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'mileage_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'condition' => ['nullable', 'string', 'max:40'],
            'preferred_payout_model' => ['nullable', Rule::enum(LeasePayoutModel::class)],
            'expected_monthly' => ['nullable', 'string', 'max:24'],
            'expected_currency' => [
                'nullable',
                'required_with:expected_monthly',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'available_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $expectedMinor = null;
        $expectedCurrency = null;

        if (filled($validated['expected_monthly'] ?? null)) {
            $expectedCurrency = strtoupper((string) $validated['expected_currency']);

            try {
                $expectedMinor = Money::parse((string) $validated['expected_monthly'], $expectedCurrency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['expected_monthly' => $exception->getMessage()]);
            }

            if ($expectedMinor < 1) {
                throw ValidationException::withMessages([
                    'expected_monthly' => 'Tell us what you are hoping for, or leave it blank.',
                ]);
            }
        }

        $email = mb_strtolower(trim((string) $validated['contact_email']));
        $phone = preg_replace('/[\s().-]+/', '', trim((string) $validated['contact_phone'])) ?? '';
        $plate = mb_strtoupper(trim((string) $validated['registration_plate']));

        // Keyed to the applicant *and* the plate, so somebody offering two cars
        // files two applications rather than one silently deduplicated.
        $ownerKey = ($owner === null ? 'guest|'.$email.'|'.$phone : 'owner|'.$owner->getKey())
            .'|plate:'.$plate;

        return [
            'attributes' => [
                'contact_name' => trim((string) $validated['contact_name']),
                'contact_email' => $email,
                'contact_phone' => $phone,
                'make' => trim((string) $validated['make']),
                'model' => trim((string) $validated['model']),
                'year' => (int) $validated['year'],
                'registration_plate' => $plate,
                'colour' => $this->nullable($validated['colour'] ?? null),
                'transmission' => $this->nullable($validated['transmission'] ?? null),
                'fuel_type' => $this->nullable($validated['fuel_type'] ?? null),
                'seating_capacity' => $validated['seating_capacity'] ?? null,
                'mileage_km' => $validated['mileage_km'] ?? null,
                'condition' => $this->nullable($validated['condition'] ?? null),
                'preferred_payout_model' => filled($validated['preferred_payout_model'] ?? null)
                    ? LeasePayoutModel::from((string) $validated['preferred_payout_model'])
                    : null,
                'expected_monthly_minor' => $expectedMinor,
                'expected_currency' => $expectedCurrency,
                'available_from' => filled($validated['available_from'] ?? null)
                    ? (string) $validated['available_from']
                    : null,
                'notes' => $this->nullable($validated['notes'] ?? null),
            ],
            'idempotency_owner_hash' => hash_hmac('sha256', $ownerKey, (string) config('app.key')),
            'idempotency_key' => (string) $validated['idempotency_key'],
        ];
    }

    private function notify(VehicleLeaseApplication $application): void
    {
        $notification = new LeaseApplicationReceivedNotification(
            reference: $application->reference,
            vehicleLabel: $application->vehicleLabel(),
        );

        $application->loadMissing('owner');

        if ($application->owner !== null) {
            $application->owner->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$application->contact_email => $application->contact_name])
            ->notify($notification);
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
