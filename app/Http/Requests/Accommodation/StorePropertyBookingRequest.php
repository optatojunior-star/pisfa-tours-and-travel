<?php

namespace App\Http\Requests\Accommodation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only.
 *
 * Availability, pricing, occupancy, and the minimum stay all belong to
 * CreatePropertyBooking, which decides them under a lock. This request exists so
 * an obviously malformed form reports itself before the action has to.
 */
class StorePropertyBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A stay is a paid, contended commitment, so it needs an account. The
        // action re-checks the role and verification under a lock.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'property_room_type_id' => ['required', 'integer', 'exists:property_room_types,id'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'adults' => ['required', 'integer', 'min:1', 'max:60'],
            'children' => ['nullable', 'integer', 'min:0', 'max:60'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'special_requests' => ['nullable', 'string', 'max:5000'],
            'acknowledge_request' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a phone number we can reach you on, for example +256700000000.',
            'acknowledge_request.accepted' => 'Please confirm you understand this is a request, not a confirmed booking.',
            'check_out_date.after' => 'Departure has to be at least one night after arrival.',
        ];
    }
}
