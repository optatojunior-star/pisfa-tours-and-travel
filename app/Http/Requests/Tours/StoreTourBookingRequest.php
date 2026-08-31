<?php

namespace App\Http\Requests\Tours;

use App\Models\TourBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTourBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TourBooking::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'departure_id' => ['required', 'integer', 'exists:tour_departures,id'],
            'idempotency_key' => ['required', 'uuid'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'accept_terms' => ['accepted'],
            'travelers' => ['required', 'array', 'min:1', 'max:'.config('tours.maximum_booking_travelers', 50)],
            'travelers.*.full_name' => ['required', 'string', 'max:120'],
            'travelers.*.traveler_type' => ['required', Rule::in(['adult', 'child'])],
            'travelers.*.date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'travelers.*.nationality' => ['nullable', 'string', 'max:100'],
            'travelers.*.dietary_notes' => ['nullable', 'string', 'max:500'],
            'travelers.*.accessibility_notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Please acknowledge the booking and cancellation terms.',
            'travelers.*.full_name.required' => 'Every traveler needs a full name.',
        ];
    }
}
