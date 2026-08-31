<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Open to the public: a guest may enquire about a car.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $guest = $this->user() === null;

        return [
            // A signed-in customer's identity comes from their account, never
            // from the form.
            'contact_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'min:2', 'max:180'],
            'contact_email' => [Rule::requiredIf($guest), 'nullable', 'email:rfc', 'max:254'],
            'contact_phone' => [
                Rule::requiredIf($guest),
                'nullable',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'message' => ['nullable', 'string', 'max:2000'],
            'offer' => ['nullable', 'string', 'max:24'],
            'offer_currency' => [
                'nullable',
                'required_with:offer',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a phone number we can reach you on, for example +256700000000.',
        ];
    }
}
