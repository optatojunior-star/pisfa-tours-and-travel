<?php

namespace Database\Factories;

use App\Enums\CarHireDocumentType;
use App\Models\CarHireBooking;
use App\Models\CarHireDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CarHireDocument> */
class CarHireDocumentFactory extends Factory
{
    public function definition(): array
    {
        $content = 'safe-test-document-'.Str::uuid();

        return [
            'car_hire_booking_id' => CarHireBooking::factory()->selfDrive(),
            'document_type' => CarHireDocumentType::NationalId,
            'disk' => (string) config('car_hire.documents.disk', 'local'),
            'path' => 'car-hire/documents/'.Str::uuid().'.pdf',
            'original_name' => 'identity-document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'uploaded_by_user_id' => fn (array $attributes): int => CarHireBooking::query()
                ->findOrFail($attributes['car_hire_booking_id'])->customer_id,
        ];
    }

    public function nationalId(): static
    {
        return $this->state(fn (): array => [
            'document_type' => CarHireDocumentType::NationalId,
            'original_name' => 'national-id.pdf',
        ]);
    }

    public function drivingPermit(): static
    {
        return $this->state(fn (): array => [
            'document_type' => CarHireDocumentType::DrivingPermit,
            'original_name' => 'driving-permit.pdf',
        ]);
    }

    public function applicantPhoto(): static
    {
        return $this->state(fn (): array => [
            'document_type' => CarHireDocumentType::ApplicantPhoto,
            'path' => 'car-hire/documents/'.Str::uuid().'.jpg',
            'original_name' => 'applicant-photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);
    }
}
