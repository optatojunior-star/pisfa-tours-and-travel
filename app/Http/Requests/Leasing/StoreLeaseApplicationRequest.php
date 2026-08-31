<?php

namespace App\Http\Requests\Leasing;

use App\Enums\LeasePayoutModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaseApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Open to the public: an owner may offer a car before they have an
        // account. The lease itself later requires one.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $guest = $this->user() === null;
        $currentYear = (int) now()->format('Y');

        return [
            'contact_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'min:2', 'max:180'],
            'contact_email' => [Rule::requiredIf($guest), 'nullable', 'email:rfc', 'max:254'],
            'contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
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
            'acknowledge_request' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a phone number we can reach you on, for example +256700000000.',
            'acknowledge_request.accepted' => 'Please confirm you understand this is an offer, not an agreement.',
            'registration_plate.required' => 'We need the registration to identify the vehicle.',
        ];
    }
}
