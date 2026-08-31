<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accept or decline. Authorization is deliberately not done here: the guest
 * route has no user to check, and the action re-proves ownership against the
 * locked row either way.
 */
class RespondToQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'decline'])],
            'reason' => [
                'nullable',
                'string',
                'min:3',
                'max:500',
                Rule::requiredIf($this->input('decision') === 'decline'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tell us briefly why, so we can offer something that works.',
        ];
    }

    public function accepted(): bool
    {
        return $this->validated('decision') === 'accept';
    }
}
