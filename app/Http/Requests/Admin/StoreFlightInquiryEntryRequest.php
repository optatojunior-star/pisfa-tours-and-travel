<?php

namespace App\Http\Requests\Admin;

use App\Enums\FlightInquiryEntryType;
use App\Models\FlightInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlightInquiryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('flightInquiry');

        return $inquiry instanceof FlightInquiry
            && ($this->user()?->can('comment', $inquiry) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entry_type' => [
                'required',
                Rule::in(array_map(
                    static fn (FlightInquiryEntryType $type): string => $type->value,
                    FlightInquiryEntryType::manualCases(),
                )),
            ],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body', ''))]);
    }
}
