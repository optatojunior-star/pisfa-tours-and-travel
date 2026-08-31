<?php

namespace App\Http\Requests\Admin;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

class RecordManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $payment instanceof Payment
            && ($this->user()?->can('record', Payment::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The bank slip or receipt number an operator is recording against.
            // Required, because a manual settlement with no evidence reference
            // is unauditable.
            'evidence_reference' => ['required', 'string', 'min:3', 'max:120'],
            'confirm_received' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm_received.accepted' => 'Confirm that the funds have actually been received.',
            'evidence_reference.required' => 'Record the bank slip or receipt reference for this payment.',
        ];
    }
}
