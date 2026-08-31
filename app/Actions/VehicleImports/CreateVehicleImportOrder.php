<?php

namespace App\Actions\VehicleImports;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportStatus;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use App\Models\User;
use App\Models\VehicleImportOrder;
use App\Notifications\VehicleImports\VehicleImportReceivedNotification;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateVehicleImportOrder
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(?User $customer, array $attributes, string $idempotencyKey): VehicleImportOrder
    {
        $input = $this->validated($customer, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $input): VehicleImportOrder {
            $lockedCustomer = null;

            if ($customer !== null) {
                $lockedCustomer = User::query()
                    ->whereKey($customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCustomer->status !== AccountStatus::Active
                    || ! $lockedCustomer->hasRole(UserRole::Customer)) {
                    throw new AuthorizationException;
                }
            }

            $existing = VehicleImportOrder::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $order = new VehicleImportOrder;
            $order->forceFill(array_merge($input['attributes'], [
                'reference' => 'IMP-'.Str::upper((string) Str::ulid()),
                // 64 hex characters of CSPRNG output. This is the guest's only
                // credential, so it is never derived from the reference.
                'tracking_token' => bin2hex(random_bytes(32)),
                'customer_id' => $lockedCustomer?->getKey(),
                'status' => VehicleImportStatus::Inquiry,
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ]))->save();

            $order->recordEvent(
                VehicleImportEventType::StatusChanged,
                'Import request received.',
                ['to' => VehicleImportStatus::Inquiry->value],
                $lockedCustomer,
            );

            $this->auditLogger->record(
                event: 'vehicle_import.created',
                auditable: $order,
                newValues: [
                    'reference' => $order->reference,
                    'customer_id' => $order->customer_id,
                    'make' => $order->make,
                    'model' => $order->model,
                    'units' => $order->units,
                    'budget_minor' => $order->budget_minor,
                    'budget_currency' => $order->budget_currency,
                    'guest_request' => $order->isGuest(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(fn () => $this->notify($order));

            return $order;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, idempotency_owner_hash: string, idempotency_key: string}
     */
    private function validated(?User $customer, array $attributes, string $idempotencyKey): array
    {
        if ($customer !== null) {
            // A signed-in customer never supplies their own identity.
            $attributes = array_merge($attributes, [
                'contact_name' => $customer->name,
                'contact_email' => $customer->email,
                'contact_phone' => $customer->phone,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);
        $currentYear = (int) now()->format('Y');

        $validated = Validator::make($attributes, [
            'make' => ['required', 'string', 'min:2', 'max:60'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'year_from' => ['required', 'integer', 'min:1980', 'max:'.($currentYear + 1)],
            'year_to' => ['required', 'integer', 'min:1980', 'max:'.($currentYear + 1), 'gte:year_from'],
            'body_type' => ['required', Rule::enum(VehicleImportBodyType::class)],
            'fuel_type' => ['required', Rule::enum(VehicleImportFuelType::class)],
            'transmission' => ['required', Rule::enum(VehicleImportTransmission::class)],
            'drive_type' => ['required', Rule::enum(VehicleImportDriveType::class)],
            'steering' => ['required', Rule::enum(VehicleImportSteering::class)],
            'engine_capacity_cc' => ['nullable', 'integer', 'min:600', 'max:10000'],
            'origin_country' => ['required', 'string', 'size:2', 'alpha'],
            'maximum_mileage_km' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'auction_grade' => ['nullable', 'string', 'max:16'],
            'preferred_colour' => ['nullable', 'string', 'max:40'],
            'units' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('vehicle_imports.maximum_units', 20),
            ],
            'purpose' => [
                'required',
                Rule::in(array_keys((array) config('vehicle_imports.purposes', []))),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'budget' => ['required', 'string', 'max:24'],
            'budget_currency' => [
                'required',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $currency = strtoupper($validated['budget_currency']);

        try {
            $budgetMinor = Money::parse($validated['budget'], $currency);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['budget' => $exception->getMessage()]);
        }

        $minimumBudget = (int) config('vehicle_imports.minimum_budget.'.$currency, 0);

        if ($budgetMinor < $minimumBudget) {
            throw ValidationException::withMessages([
                'budget' => 'The minimum import budget is '.Money::format($minimumBudget, $currency).'.',
            ]);
        }

        $email = mb_strtolower(trim($validated['contact_email']));
        $phone = preg_replace('/[\s().-]+/', '', trim($validated['contact_phone'])) ?? '';

        $owner = $customer === null
            ? 'guest|'.$email.'|'.$phone
            : 'customer|'.$customer->getKey();

        return [
            'attributes' => [
                'make' => trim($validated['make']),
                'model' => trim($validated['model']),
                'year_from' => (int) $validated['year_from'],
                'year_to' => (int) $validated['year_to'],
                'body_type' => VehicleImportBodyType::from($validated['body_type']),
                'fuel_type' => VehicleImportFuelType::from($validated['fuel_type']),
                'transmission' => VehicleImportTransmission::from($validated['transmission']),
                'drive_type' => VehicleImportDriveType::from($validated['drive_type']),
                'steering' => VehicleImportSteering::from($validated['steering']),
                'engine_capacity_cc' => $validated['engine_capacity_cc'] ?? null,
                'origin_country' => strtoupper($validated['origin_country']),
                'maximum_mileage_km' => $validated['maximum_mileage_km'] ?? null,
                'auction_grade' => $this->nullable($validated['auction_grade'] ?? null),
                'preferred_colour' => $this->nullable($validated['preferred_colour'] ?? null),
                'units' => (int) $validated['units'],
                'purpose' => $validated['purpose'],
                'notes' => $this->nullable($validated['notes'] ?? null),
                'budget_minor' => $budgetMinor,
                'budget_currency' => $currency,
                'contact_name' => trim($validated['contact_name']),
                'contact_email' => $email,
                'contact_phone' => $phone,
            ],
            'idempotency_owner_hash' => hash_hmac('sha256', $owner, (string) config('app.key')),
            'idempotency_key' => $validated['idempotency_key'],
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function notify(VehicleImportOrder $order): void
    {
        $notification = new VehicleImportReceivedNotification(
            recipientName: $order->contact_name,
            reference: $order->reference,
            vehicleSummary: $order->vehicleSummary(),
            units: $order->units,
            budget: $order->formattedBudget(),
            trackingUrl: $order->paymentReturnUrl(),
        );

        $order->loadMissing('customer');

        if ($order->customer !== null) {
            $order->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$order->contact_email => $order->contact_name])
            ->notify($notification);
    }
}
