<?php

namespace Tests\Feature\Tours\Concerns;

use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\UserRole;
use App\Models\TourBooking;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\TourTraveler;
use App\Models\User;

trait BuildsTourFixtures
{
    protected function customer(array $attributes = []): User
    {
        return $this->user(UserRole::Customer, $attributes);
    }

    protected function operationsUser(UserRole $role = UserRole::Staff, array $attributes = []): User
    {
        return $this->user($role, $attributes);
    }

    protected function driver(array $attributes = []): User
    {
        return $this->user(UserRole::Driver, $attributes);
    }

    protected function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    protected function publishedTour(array $attributes = [], ?TourCategory $category = null): TourPackage
    {
        $category ??= TourCategory::factory()->create(['is_active' => true]);

        return TourPackage::factory()
            ->published()
            ->for($category, 'category')
            ->create(array_merge([
                'duration_days' => 2,
                'base_price_minor' => 250_000,
                'currency' => 'UGX',
                'min_travelers' => 1,
                'max_travelers' => 12,
            ], $attributes));
    }

    protected function bookableDeparture(?TourPackage $package = null, array $attributes = []): TourDeparture
    {
        $package ??= $this->publishedTour();
        $startsAt = now()->toImmutable()->addDays(10)->startOfHour();

        return TourDeparture::factory()
            ->for($package, 'tourPackage')
            ->create(array_merge([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addDays(2),
                'cancellation_cutoff_at' => $startsAt->subDays(2),
                'capacity' => 12,
                'status' => TourDepartureStatus::Scheduled,
            ], $attributes));
    }

    /** @return array<string, mixed> */
    protected function bookingPayload(User $customer, int $travelerCount = 1, array $overrides = []): array
    {
        $travelers = [];

        for ($index = 0; $index < $travelerCount; $index++) {
            $travelers[] = [
                'full_name' => 'Traveler '.($index + 1),
                'traveler_type' => $index === 0 ? 'adult' : ($index % 2 === 0 ? 'child' : 'adult'),
                'date_of_birth' => $index % 2 === 0 ? '1990-04-12' : null,
                'nationality' => 'Ugandan',
                'dietary_notes' => null,
                'accessibility_notes' => null,
                'is_lead' => $index === 0,
            ];
        }

        return array_merge([
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'contact_phone' => $customer->phone,
            'traveler_count' => $travelerCount,
            'special_requests' => 'Window seats where available.',
            'travelers' => $travelers,
        ], $overrides);
    }

    protected function persistedBooking(
        User $customer,
        TourDeparture $departure,
        TourBookingStatus $status = TourBookingStatus::Pending,
        int $travelerCount = 1,
        array $attributes = [],
    ): TourBooking {
        $package = $departure->tourPackage;
        $timestamps = match ($status) {
            TourBookingStatus::Pending => [],
            TourBookingStatus::Confirmed => ['confirmed_at' => now()->subHour()],
            TourBookingStatus::InProgress => [
                'confirmed_at' => now()->subDays(2),
                'in_progress_at' => now()->subHour(),
            ],
            TourBookingStatus::Completed => [
                'confirmed_at' => now()->subDays(3),
                'in_progress_at' => now()->subDays(2),
                'completed_at' => now()->subDay(),
            ],
            TourBookingStatus::Cancelled => [
                'cancelled_at' => now()->subHour(),
                'cancellation_reason' => 'Cancelled for testing.',
            ],
        };

        $booking = TourBooking::factory()->create(array_merge([
            'customer_id' => $customer->getKey(),
            'tour_package_id' => $package->getKey(),
            'tour_departure_id' => $departure->getKey(),
            'status' => $status,
            'traveler_count' => $travelerCount,
            'contact_name' => $customer->name,
            'contact_email' => $customer->email,
            'contact_phone' => $customer->phone,
        ], $timestamps, $attributes));

        for ($index = 0; $index < $travelerCount; $index++) {
            TourTraveler::factory()
                ->for($booking, 'booking')
                ->create([
                    'full_name' => 'Saved Traveler '.($index + 1),
                    'is_lead' => $index === 0,
                    'sort_order' => $index,
                ]);
        }

        return $booking->fresh(['customer', 'tourPackage', 'departure', 'travelers']);
    }
}
