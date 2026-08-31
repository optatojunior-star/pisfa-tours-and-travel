<?php

namespace Database\Factories;

use App\Enums\PropertyBookingStatus;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PropertyBooking> */
class PropertyBookingFactory extends Factory
{
    public function definition(): array
    {
        $checkIn = CarbonImmutable::parse(now()->addDays(14)->toDateString());
        $checkOut = $checkIn->addDays(2);

        return [
            'reference' => 'STAY-'.Str::upper((string) Str::ulid()),
            'customer_id' => User::factory(),
            'property_id' => Property::factory(),
            'property_room_type_id' => PropertyRoomType::factory(),
            'property_room_rate_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => PropertyBookingStatus::Pending,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'nights' => 2,
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'property_name_snapshot' => 'Kazinga Lodge',
            'room_type_name_snapshot' => 'Standard Double',
            'check_in_from_snapshot' => '14:00:00',
            'check_out_by_snapshot' => '10:00:00',
            'nightly_rate_minor' => 250_000,
            'total_minor' => 500_000,
            'currency' => 'UGX',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+2567'.fake()->numerify('########'),
            // A pending booking only holds rooms while this is in the future.
            'hold_expires_at' => now()->addDay(),
            'cancellation_cutoff_at' => $checkIn->subDays(2),
        ];
    }

    public function forStay(Property $property, PropertyRoomType $roomType): static
    {
        return $this->state(fn (): array => [
            'property_id' => $property->getKey(),
            'property_room_type_id' => $roomType->getKey(),
            'property_name_snapshot' => $property->name,
            'room_type_name_snapshot' => $roomType->name,
        ]);
    }

    public function forCustomer(User $customer): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $customer->getKey(),
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
        ]);
    }

    /** @param  string  $checkIn  A calendar date, not a moment. */
    public function nights(string $checkIn, string $checkOut): static
    {
        return $this->state(function () use ($checkIn, $checkOut): array {
            $from = CarbonImmutable::parse($checkIn)->startOfDay();
            $to = CarbonImmutable::parse($checkOut)->startOfDay();

            return [
                'check_in_date' => $from->toDateString(),
                'check_out_date' => $to->toDateString(),
                'nights' => (int) $from->diffInDays($to),
                'cancellation_cutoff_at' => $from->subDays(2),
            ];
        });
    }

    public function rooms(int $rooms): static
    {
        return $this->state(fn (): array => ['rooms' => $rooms]);
    }

    public function status(PropertyBookingStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            // A confirmed stay is not a hold any more.
            'hold_expires_at' => $status === PropertyBookingStatus::Pending ? now()->addDay() : null,
            'confirmed_at' => $status === PropertyBookingStatus::Pending ? null : now()->subHour(),
        ]);
    }

    /** A pending booking whose hold has lapsed: it no longer occupies a room. */
    public function holdLapsed(): static
    {
        return $this->state(fn (): array => [
            'status' => PropertyBookingStatus::Pending,
            'hold_expires_at' => now()->subHour(),
        ]);
    }
}
