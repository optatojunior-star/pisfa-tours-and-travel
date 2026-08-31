<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireSelfDriveApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarHireSelfDriveApplication> */
class CarHireSelfDriveApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'car_hire_booking_id' => CarHireBooking::factory()->selfDrive(),
            'status' => SelfDriveApplicationStatus::Draft,
            'national_id_number' => null,
            'driving_permit_number' => null,
            'date_of_birth' => null,
            'driving_permit_issuing_country' => null,
            'driving_permit_class' => null,
            'driving_permit_issued_on' => null,
            'driving_permit_expires_on' => null,
            'declaration_accepted_at' => null,
            'submitted_at' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_reason' => null,
            'internal_review_notes' => null,
            'originals_verified_at' => null,
            'originals_verified_by_user_id' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => $this->submittedAttributes());
    }

    public function needsInformation(): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => SelfDriveApplicationStatus::NeedsInformation,
            'reviewed_by_user_id' => $this->operationsUserFactory(),
            'reviewed_at' => now(),
            'review_reason' => 'Please upload a clearer driving permit copy.',
        ]);
    }

    public function approved(): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => SelfDriveApplicationStatus::Approved,
            'reviewed_by_user_id' => $this->operationsUserFactory(),
            'reviewed_at' => now(),
            'review_reason' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->submitted()->state(fn (): array => [
            'status' => SelfDriveApplicationStatus::Rejected,
            'reviewed_by_user_id' => $this->operationsUserFactory(),
            'reviewed_at' => now(),
            'review_reason' => 'The supplied permit could not be verified.',
        ]);
    }

    public function originalsVerified(): static
    {
        return $this->approved()->state(fn (): array => [
            'originals_verified_at' => now(),
            'originals_verified_by_user_id' => $this->operationsUserFactory(),
        ]);
    }

    /** @return array<string, mixed> */
    private function submittedAttributes(): array
    {
        return [
            'status' => SelfDriveApplicationStatus::Submitted,
            'national_id_number' => 'TEST-NIN-'.fake()->unique()->numerify('########'),
            'driving_permit_number' => 'TEST-PERMIT-'.fake()->unique()->numerify('########'),
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'driving_permit_issuing_country' => 'Uganda',
            'driving_permit_class' => 'B',
            'driving_permit_issued_on' => now()->subYears(5)->toDateString(),
            'driving_permit_expires_on' => now()->addYears(2)->toDateString(),
            'declaration_accepted_at' => now(),
            'submitted_at' => now(),
        ];
    }

    private function operationsUserFactory(): UserFactory
    {
        return User::factory()->state([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }
}
