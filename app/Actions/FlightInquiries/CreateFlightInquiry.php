<?php

namespace App\Actions\FlightInquiries;

use App\Actions\FlightInquiries\Concerns\InteractsWithFlightInquiryDomain;
use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use App\Enums\FlightTravelClass;
use App\Enums\FlightTripType;
use App\Models\FlightInquiry;
use App\Models\User;
use App\Notifications\FlightInquiries\FlightInquiryReceivedNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use JsonException;

class CreateFlightInquiry
{
    use InteractsWithFlightInquiryDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(?User $customer, array $attributes, string $idempotencyKey): FlightInquiry
    {
        $input = $this->validatedInput($customer, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $input): FlightInquiry {
            $lockedCustomer = null;

            if ($customer !== null) {
                $lockedCustomer = User::query()
                    ->whereKey($customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->ensureInquiryCustomer($lockedCustomer);
            }

            $existing = FlightInquiry::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $input['request_fingerprint'])) {
                    $this->invalid(
                        'idempotency_key',
                        'This idempotency key was already used for a different flight inquiry.',
                    );
                }

                return $existing;
            }

            $now = now();
            $inquiry = new FlightInquiry;
            $inquiry->forceFill([
                'reference' => 'FLT-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer?->getKey(),
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
                'request_fingerprint' => $input['request_fingerprint'],
                'status' => FlightInquiryStatus::New,
                'scope' => $input['scope'],
                'trip_type' => $input['trip_type'],
                'travel_class' => $input['travel_class'],
                'origin' => $input['origin'],
                'destination' => $input['destination'],
                'outbound_on' => $input['outbound_on'],
                'return_on' => $input['return_on'],
                'passenger_count' => $input['passenger_count'],
                'contact_name' => $input['contact_name'],
                'contact_email' => $input['contact_email'],
                'contact_phone' => $input['contact_phone'],
                'notes' => $input['notes'],
                'acknowledged_at' => $now,
            ])->save();

            $this->auditLogger->record(
                event: 'flight_inquiry.created',
                auditable: $inquiry,
                newValues: [
                    'reference' => $inquiry->reference,
                    'customer_id' => $inquiry->customer_id,
                    'status' => FlightInquiryStatus::New->value,
                    'scope' => $inquiry->scope->value,
                    'trip_type' => $inquiry->trip_type->value,
                    'travel_class' => $inquiry->travel_class->value,
                    'origin' => $inquiry->origin,
                    'destination' => $inquiry->destination,
                    'outbound_on' => $inquiry->outbound_on->toDateString(),
                    'return_on' => $inquiry->return_on?->toDateString(),
                    'passenger_count' => $inquiry->passenger_count,
                    'guest_request' => $inquiry->isGuest(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(fn () => $this->notifyInquiryRecipient(
                $inquiry,
                new FlightInquiryReceivedNotification(
                    recipientName: $inquiry->contact_name,
                    inquiryReference: $inquiry->reference,
                    scopeLabel: $inquiry->scope->label(),
                    routeLabel: $inquiry->routeLabel(),
                    outboundOn: $inquiry->outbound_on->toDateString(),
                    returnOn: $inquiry->return_on?->toDateString(),
                    passengerCount: $inquiry->passenger_count,
                    travelClassLabel: $inquiry->travel_class->label(),
                    viewUrl: $this->inquiryViewUrl($inquiry),
                ),
            ));

            return $inquiry;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(?User $customer, array $attributes, string $idempotencyKey): array
    {
        if ($customer !== null) {
            $attributes = array_merge($attributes, [
                'contact_name' => $customer->name,
                'contact_email' => $customer->email,
                'contact_phone' => $customer->phone,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);

        $validated = Validator::make($attributes, [
            'scope' => ['required', Rule::enum(FlightInquiryScope::class)],
            'trip_type' => ['required', Rule::enum(FlightTripType::class)],
            'travel_class' => ['required', Rule::enum(FlightTravelClass::class)],
            'origin' => ['required', 'string', 'min:2', 'max:120'],
            'destination' => ['required', 'string', 'min:2', 'max:120'],
            'outbound_on' => ['required', 'date_format:Y-m-d'],
            'return_on' => ['nullable', 'date_format:Y-m-d'],
            'passenger_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('flight_inquiries.maximum_passengers', 50),
            ],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $scope = $validated['scope'] instanceof FlightInquiryScope
            ? $validated['scope']
            : FlightInquiryScope::from($validated['scope']);
        $tripType = $validated['trip_type'] instanceof FlightTripType
            ? $validated['trip_type']
            : FlightTripType::from($validated['trip_type']);
        $travelClass = $validated['travel_class'] instanceof FlightTravelClass
            ? $validated['travel_class']
            : FlightTravelClass::from($validated['travel_class']);

        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $outbound = CarbonImmutable::createFromFormat('!Y-m-d', $validated['outbound_on'], $timezone);
        $return = filled($validated['return_on'] ?? null)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['return_on'], $timezone)
            : null;

        // Carbon signals a parse failure with null in this version, so an
        // instance check is the guard that actually fires. A `=== false` test
        // here would be dead code and let an unparseable date through.
        if (! $outbound instanceof CarbonImmutable
            || (filled($validated['return_on'] ?? null) && ! $return instanceof CarbonImmutable)) {
            $this->invalid('outbound_on', 'Enter valid travel dates.');
        }

        $earliest = $today->addDays((int) config('flight_inquiries.minimum_notice_days', 1));
        $latest = $today->addDays((int) config('flight_inquiries.maximum_advance_days', 365));

        if ($outbound->isBefore($earliest)) {
            $this->invalid('outbound_on', 'Choose an outbound date that meets the minimum enquiry notice.');
        }

        if ($outbound->isAfter($latest)) {
            $this->invalid('outbound_on', 'The outbound date is beyond the supported planning window.');
        }

        if ($tripType->requiresReturnDate()) {
            if ($return === null) {
                $this->invalid('return_on', 'Enter a return date for a return trip.');
            }

            if (! $return->isAfter($outbound)) {
                $this->invalid('return_on', 'The return date must be after the outbound date.');
            }

            $maximumLength = (int) config('flight_inquiries.maximum_trip_length_days', 180);

            if ($outbound->diffInDays($return) > $maximumLength) {
                $this->invalid('return_on', "A trip cannot span more than {$maximumLength} days.");
            }
        } else {
            // A one-way enquiry never carries a return date, whatever the form
            // left behind in a hidden field.
            $return = null;
        }

        $origin = trim($validated['origin']);
        $destination = trim($validated['destination']);

        if (mb_strtolower($origin) === mb_strtolower($destination)) {
            $this->invalid('destination', 'The destination must differ from the origin.');
        }

        $normalized = [
            'scope' => $scope,
            'trip_type' => $tripType,
            'travel_class' => $travelClass,
            'origin' => $origin,
            'destination' => $destination,
            'outbound_on' => $outbound->toDateString(),
            'return_on' => $return?->toDateString(),
            'passenger_count' => (int) $validated['passenger_count'],
            'contact_name' => trim($validated['contact_name']),
            'contact_email' => $this->normalizedEmail($validated['contact_email']),
            'contact_phone' => $this->normalizedPhone($validated['contact_phone']),
            'notes' => $this->nullableString($validated['notes'] ?? null),
            'idempotency_key' => $validated['idempotency_key'],
        ];

        $normalized['idempotency_owner_hash'] = $this->idempotencyOwnerHash(
            $customer,
            $normalized['contact_email'],
            $normalized['contact_phone'],
        );

        try {
            $normalized['request_fingerprint'] = $this->requestFingerprint([
                'owner_hash' => $normalized['idempotency_owner_hash'],
                'scope' => $scope->value,
                'trip_type' => $tripType->value,
                'travel_class' => $travelClass->value,
                'origin' => $normalized['origin'],
                'destination' => $normalized['destination'],
                'outbound_on' => $normalized['outbound_on'],
                'return_on' => $normalized['return_on'],
                'passenger_count' => $normalized['passenger_count'],
                'contact_name' => $normalized['contact_name'],
                'contact_email' => $normalized['contact_email'],
                'contact_phone' => $normalized['contact_phone'],
                'notes' => $normalized['notes'],
            ]);
        } catch (JsonException) {
            $this->invalid('idempotency_key', 'The flight inquiry could not be normalized.');
        }

        return $normalized;
    }
}
