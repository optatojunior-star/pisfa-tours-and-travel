<?php

namespace App\Http\Requests\Admin;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPaymentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Payment::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'provider' => ['nullable', Rule::enum(PaymentProvider::class)],
            'currency' => ['nullable', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'bucket' => ['nullable', Rule::in(['settled', 'in_flight', 'refunded', 'unreconciled'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
