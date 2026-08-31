<?php

namespace App\Http\Requests\Admin;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $payment = $this->route('payment');

        return $payment instanceof Payment
            && ($this->user()?->can('refund', $payment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Entered in major units by a human; converted with Money::parse so
            // no float ever touches the amount.
            'amount' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'confirm_refund' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('amount')
                && preg_match('/\A0+(?:\.0+)?\z/', (string) $this->input('amount')) === 1) {
                $validator->errors()->add('amount', 'Enter a refund amount greater than zero.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => trim((string) $this->input('amount', ''))]);
    }
}
