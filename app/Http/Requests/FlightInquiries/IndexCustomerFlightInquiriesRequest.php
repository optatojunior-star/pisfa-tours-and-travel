<?php

namespace App\Http\Requests\FlightInquiries;

use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCustomerFlightInquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FlightInquiryStatus::class)],
            'scope' => ['nullable', Rule::enum(FlightInquiryScope::class)],
        ];
    }
}
