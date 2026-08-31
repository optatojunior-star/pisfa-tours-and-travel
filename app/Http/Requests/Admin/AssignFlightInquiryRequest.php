<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\FlightInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignFlightInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('flightInquiry');

        return $inquiry instanceof FlightInquiry
            && ($this->user()?->can('assign', $inquiry) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_to_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', [
                        UserRole::Staff->value,
                        UserRole::Manager->value,
                        UserRole::SuperAdmin->value,
                    ])
                    ->where('status', AccountStatus::Active->value)),
            ],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'assigned_to_user_id' => filled($this->input('assigned_to_user_id'))
                ? $this->input('assigned_to_user_id')
                : null,
        ]);
    }
}
