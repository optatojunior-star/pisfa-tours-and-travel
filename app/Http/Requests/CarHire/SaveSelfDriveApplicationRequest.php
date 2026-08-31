<?php

namespace App\Http\Requests\CarHire;

use Illuminate\Foundation\Http\FormRequest;

class SaveSelfDriveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->booking();

        return $booking !== null
            && ($this->user()?->can('updateSelfDriveApplication', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->routeIs('*.submit') ? 'required' : 'nullable';

        return [
            'national_id_number' => [$required, 'string', 'max:100'],
            'driving_permit_number' => [$required, 'string', 'max:100'],
            'date_of_birth' => [$required, 'date_format:Y-m-d', 'before:today'],
            'driving_permit_issuing_country' => [$required, 'string', 'max:100'],
            'driving_permit_class' => [$required, 'string', 'max:40'],
            'driving_permit_issued_on' => [$required, 'date_format:Y-m-d', 'before_or_equal:today'],
            'driving_permit_expires_on' => [
                $required,
                'date_format:Y-m-d',
                'after:today',
                'after:driving_permit_issued_on',
            ],
            'declaration_accepted' => $this->routeIs('*.submit')
                ? ['required', 'accepted']
                : ['sometimes', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'declaration_accepted.accepted' => 'Accept the self-drive declaration before submitting the application.',
        ];
    }

    private function booking(): mixed
    {
        return $this->route('customerCarHireBooking')
            ?? $this->route('customerHireBooking')
            ?? $this->route('carHireBooking')
            ?? $this->route('hireBooking');
    }
}
