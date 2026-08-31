<?php

namespace App\Http\Requests\Billing;

use App\Support\ServiceCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape-checks the public quotation-request form.
 *
 * The action re-validates everything it persists — this only fails fast with
 * field-level messages the form can render.
 */
class StoreQuotationRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Open to the public: a guest may ask for a quotation.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $guest = $this->user() === null;

        return [
            'service' => ['required', Rule::in(ServiceCatalogue::keys())],
            'details' => ['required', 'string', 'min:20', 'max:5000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'budget' => ['nullable', 'string', 'max:24'],
            'budget_currency' => [
                'nullable',
                'required_with:budget',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'company_name' => ['nullable', 'string', 'max:180'],
            // A signed-in customer's identity comes from their account, never
            // from the form, so these are only required for a guest.
            'contact_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'min:2', 'max:180'],
            'contact_email' => [Rule::requiredIf($guest), 'nullable', 'email:rfc', 'max:254'],
            'contact_phone' => [
                Rule::requiredIf($guest),
                'nullable',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'details.min' => 'Tell us a little more so we can price it accurately.',
            'contact_phone.regex' => 'Enter a phone number we can reach you on, for example +256700000000.',
        ];
    }
}
