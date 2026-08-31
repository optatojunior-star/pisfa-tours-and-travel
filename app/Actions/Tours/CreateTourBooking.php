<?php

namespace App\Actions\Tours;

use App\Actions\Tours\Concerns\InteractsWithTourDomain;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Enums\TourTravelerType;
use App\Enums\UserRole;
use App\Models\TourBooking;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use App\Notifications\Tours\TourBookingReceivedNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateTourBooking
{
    use InteractsWithTourDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(
        User $customer,
        TourDeparture $departure,
        array $attributes,
        string $idempotencyKey,
    ): TourBooking {
        $idempotencyKey = trim($idempotencyKey);

        Validator::make(
            ['idempotency_key' => $idempotencyKey],
            ['idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/']],
        )->validate();

        $input = $this->validatedInput($customer, $attributes);

        return DB::transaction(function () use ($customer, $departure, $input, $idempotencyKey): TourBooking {
            $lockedCustomer = User::query()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $lockedCustomer->email_verified_at === null) {
                throw new AuthorizationException;
            }

            $existing = TourBooking::query()
                ->with(['travelers', 'tourPackage', 'departure'])
                ->where('customer_id', $lockedCustomer->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertReplayMatches($existing, $departure, $input);

                return $existing;
            }

            $packageId = (int) $departure->tour_package_id;
            $package = TourPackage::query()
                ->with('category')
                ->whereKey($packageId)
                ->lockForUpdate()
                ->first();

            $lockedDeparture = TourDeparture::query()
                ->whereKey($departure->getKey())
                ->lockForUpdate()
                ->first();

            if ($package === null || $lockedDeparture === null || $lockedDeparture->tour_package_id !== $package->getKey()) {
                $this->invalid('departure', 'The selected tour departure is no longer available.');
            }

            $now = now();

            if ($package->status !== TourPackageStatus::Published
                || $package->published_at === null
                || $package->published_at->isAfter($now)
                || ! $package->category?->is_active) {
                $this->invalid('departure', 'The selected tour package is not available for booking.');
            }

            if ($lockedDeparture->status !== TourDepartureStatus::Scheduled
                || ! $lockedDeparture->starts_at->isAfter($now)
                || ! $lockedDeparture->cancellation_cutoff_at->isAfter($now)) {
                $this->invalid('departure', 'Bookings are closed for this departure.');
            }

            $travelerCount = count($input['travelers']);

            if ($travelerCount < $package->min_travelers || $travelerCount > $package->max_travelers) {
                $this->invalid(
                    'travelers',
                    "This package accepts between {$package->min_travelers} and {$package->max_travelers} travelers per booking.",
                );
            }

            if (array_key_exists('traveler_count', $input) && (int) $input['traveler_count'] !== $travelerCount) {
                $this->invalid('traveler_count', 'The traveler count does not match the traveler list.');
            }

            $reservedSeats = (int) $lockedDeparture->bookings()
                ->holdingCapacity()
                ->sum('traveler_count');

            if ($reservedSeats + $travelerCount > $lockedDeparture->capacity) {
                $remaining = max(0, $lockedDeparture->capacity - $reservedSeats);
                $this->invalid('travelers', "Only {$remaining} seat(s) remain for this departure.");
            }

            $lockedDeparture->setRelation('tourPackage', $package);
            $currency = $lockedDeparture->effectiveCurrency();

            if (($input['currency'] ?? $currency) !== $currency) {
                $this->invalid('currency', 'The selected departure is not priced in that currency.');
            }

            $unitPriceMinor = $lockedDeparture->effectivePriceMinor();

            if ($travelerCount > 0 && $unitPriceMinor > intdiv(PHP_INT_MAX, $travelerCount)) {
                $this->invalid('travelers', 'The booking total is too large.');
            }

            $totalMinor = $unitPriceMinor * $travelerCount;

            $booking = TourBooking::query()->create([
                'reference' => 'TOUR-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer->getKey(),
                'tour_package_id' => $package->getKey(),
                'tour_departure_id' => $lockedDeparture->getKey(),
                'idempotency_key' => $idempotencyKey,
                'status' => TourBookingStatus::Pending,
                'traveler_count' => $travelerCount,
                'package_name_snapshot' => $package->name,
                'destination_snapshot' => $package->destination,
                'departure_starts_at_snapshot' => $lockedDeparture->starts_at,
                'departure_ends_at_snapshot' => $lockedDeparture->ends_at,
                'cancellation_cutoff_at_snapshot' => $lockedDeparture->cancellation_cutoff_at,
                'unit_price_minor' => $unitPriceMinor,
                'subtotal_minor' => $totalMinor,
                'total_minor' => $totalMinor,
                'currency' => $currency,
                'contact_name' => $input['contact_name'],
                'contact_email' => $input['contact_email'],
                'contact_phone' => $input['contact_phone'],
                'special_requests' => $this->nullableString($input['special_requests'] ?? null),
            ]);

            $booking->travelers()->createMany($input['travelers']);

            $this->auditLogger->record(
                event: 'tour_booking.created',
                auditable: $booking,
                newValues: [
                    'reference' => $booking->reference,
                    'tour_package_id' => $package->getKey(),
                    'tour_departure_id' => $lockedDeparture->getKey(),
                    'status' => TourBookingStatus::Pending->value,
                    'traveler_count' => $travelerCount,
                    'unit_price_minor' => $unitPriceMinor,
                    'total_minor' => $totalMinor,
                    'currency' => $currency,
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(function () use ($lockedCustomer, $booking, $package, $lockedDeparture): void {
                $lockedCustomer->notify(new TourBookingReceivedNotification(
                    bookingReference: $booking->reference,
                    tourName: $package->name,
                    departureStartsAt: $lockedDeparture->starts_at->toIso8601String(),
                    totalMinor: $booking->total_minor,
                    currency: $booking->currency,
                ));
            });

            return $booking->load(['travelers', 'tourPackage', 'departure']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(User $customer, array $attributes): array
    {
        $attributes = array_merge([
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'contact_phone' => $customer->phone,
        ], $attributes);

        $maximum = (int) config('tours.maximum_booking_travelers', 50);

        $validated = Validator::make($attributes, [
            'contact_name' => ['required', 'string', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'traveler_count' => ['nullable', 'integer', 'min:1', 'max:'.$maximum],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'travelers' => ['required', 'array', 'min:1', 'max:'.$maximum],
            'travelers.*.full_name' => ['required', 'string', 'max:180'],
            'travelers.*.traveler_type' => ['required', Rule::enum(TourTravelerType::class)],
            'travelers.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'travelers.*.nationality' => ['nullable', 'string', 'max:100'],
            'travelers.*.dietary_notes' => ['nullable', 'string', 'max:2000'],
            'travelers.*.accessibility_notes' => ['nullable', 'string', 'max:2000'],
            'travelers.*.is_lead' => ['nullable', 'boolean'],
        ])->validate();

        $validated['contact_name'] = trim($validated['contact_name']);
        $validated['contact_email'] = mb_strtolower(trim($validated['contact_email']));
        $validated['contact_phone'] = trim($validated['contact_phone']);

        if (isset($validated['currency'])) {
            $validated['currency'] = $this->currency($validated['currency']);
        }

        $leadIndexes = [];

        foreach ($validated['travelers'] as $index => &$traveler) {
            $traveler['full_name'] = trim($traveler['full_name']);
            $traveler['traveler_type'] = $traveler['traveler_type'] instanceof TourTravelerType
                ? $traveler['traveler_type']
                : TourTravelerType::from($traveler['traveler_type']);
            $traveler['date_of_birth'] = $this->nullableString($traveler['date_of_birth'] ?? null);
            $traveler['nationality'] = $this->nullableString($traveler['nationality'] ?? null);
            $traveler['dietary_notes'] = $this->nullableString($traveler['dietary_notes'] ?? null);
            $traveler['accessibility_notes'] = $this->nullableString($traveler['accessibility_notes'] ?? null);
            $traveler['is_lead'] = (bool) ($traveler['is_lead'] ?? false);
            $traveler['sort_order'] = $index;

            if ($traveler['is_lead']) {
                $leadIndexes[] = $index;
            }
        }
        unset($traveler);

        if (count($leadIndexes) > 1) {
            $this->invalid('travelers', 'Select only one lead traveler.');
        }

        if ($leadIndexes === []) {
            $validated['travelers'][0]['is_lead'] = true;
        }

        return $validated;
    }

    /** @param array<string, mixed> $input */
    private function assertReplayMatches(TourBooking $booking, TourDeparture $departure, array $input): void
    {
        $existingTravelers = $booking->travelers
            ->sortBy('sort_order')
            ->values()
            ->map(static fn ($traveler): array => [
                'full_name' => $traveler->full_name,
                'traveler_type' => $traveler->traveler_type->value,
                'date_of_birth' => $traveler->date_of_birth?->format('Y-m-d'),
                'nationality' => $traveler->nationality,
                'dietary_notes' => $traveler->dietary_notes,
                'accessibility_notes' => $traveler->accessibility_notes,
                'is_lead' => $traveler->is_lead,
                'sort_order' => $traveler->sort_order,
            ])->all();

        $requestedTravelers = array_map(static fn (array $traveler): array => [
            'full_name' => $traveler['full_name'],
            'traveler_type' => $traveler['traveler_type']->value,
            'date_of_birth' => $traveler['date_of_birth'],
            'nationality' => $traveler['nationality'],
            'dietary_notes' => $traveler['dietary_notes'],
            'accessibility_notes' => $traveler['accessibility_notes'],
            'is_lead' => $traveler['is_lead'],
            'sort_order' => $traveler['sort_order'],
        ], $input['travelers']);

        $matches = $booking->tour_departure_id === $departure->getKey()
            && $booking->traveler_count === count($input['travelers'])
            && $booking->contact_name === $input['contact_name']
            && $booking->contact_email === $input['contact_email']
            && $booking->contact_phone === $input['contact_phone']
            && $booking->special_requests === $this->nullableString($input['special_requests'] ?? null)
            && (! isset($input['currency']) || $booking->currency === $input['currency'])
            && $existingTravelers === $requestedTravelers;

        if (! $matches) {
            $this->invalid('idempotency_key', 'This idempotency key was already used for a different booking request.');
        }
    }
}
