<?php

namespace App\Http\Requests\Admin;

use App\Enums\FlightInquiryStatus;
use App\Models\FlightInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionFlightInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('flightInquiry');

        if (! $inquiry instanceof FlightInquiry) {
            return false;
        }

        $user = $this->user();

        if ($user === null || ! $user->can('transition', $inquiry)) {
            return false;
        }

        // Returning a resolved inquiry to the queue is a separate, narrower
        // permission than an ordinary forward transition.
        if ($this->input('status') === FlightInquiryStatus::New->value
            && ! $inquiry->status->isOpen()) {
            return $user->can('reopen', $inquiry);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(FlightInquiryStatus::class)],
            'reason' => [
                'nullable',
                'string',
                'min:5',
                'max:1000',
                Rule::requiredIf(in_array($this->input('status'), [
                    FlightInquiryStatus::Closed->value,
                    FlightInquiryStatus::Cancelled->value,
                ], true)),
            ],
            'notify_traveller' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notify_traveller' => $this->boolean('notify_traveller')]);
    }
}
