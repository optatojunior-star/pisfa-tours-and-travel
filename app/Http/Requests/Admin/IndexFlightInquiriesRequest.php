<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\FlightInquiryScope;
use App\Enums\FlightInquiryStatus;
use App\Enums\FlightTripType;
use App\Enums\UserRole;
use App\Models\FlightInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFlightInquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FlightInquiry::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FlightInquiryStatus::class)],
            'scope' => ['nullable', Rule::enum(FlightInquiryScope::class)],
            'trip_type' => ['nullable', Rule::enum(FlightTripType::class)],
            'queue' => ['nullable', Rule::in(['open', 'mine', 'unassigned'])],
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
