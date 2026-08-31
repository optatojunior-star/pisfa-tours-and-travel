<?php

namespace App\Http\Requests\Admin;

use App\Models\CorporateAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only.
 *
 * Whether a credit limit may be lowered, and whether the billing currency may
 * change, belong to SaveCorporateAccount — both depend on what the company
 * currently owes, which is computed under a lock.
 */
class SaveCorporateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        if ($account instanceof CorporateAccount) {
            return $this->user()?->can('update', $account) ?? false;
        }

        return $this->user()?->can('create', CorporateAccount::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creatingTerms = ! ($this->route('account') instanceof CorporateAccount);

        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'tax_identification_number' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'string', 'max:120'],
            'billing_contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'billing_contact_email' => ['required', 'email:rfc', 'max:254'],
            'billing_contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],

            // Terms come with the form on create; afterwards they are changed
            // through their own manager-only action.
            'payment_terms_days' => [Rule::requiredIf($creatingTerms), 'integer', 'min:0', 'max:180'],
            'credit_limit' => [Rule::requiredIf($creatingTerms), 'string', 'max:24'],
            'currency' => [
                Rule::requiredIf($creatingTerms),
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'discount_bps' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(int) config('corporate.max_discount_bps', 9900),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'billing_contact_phone.regex' => 'Enter a phone number we can reach them on, for example +256700000000.',
            'discount_bps.max' => 'A discount at or above 100% would mean giving the service away.',
        ];
    }
}
