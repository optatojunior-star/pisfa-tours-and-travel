<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignCarHireDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('carHireBooking');

        return $booking instanceof CarHireBooking && ($this->user()?->can('assignDriver', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::Driver->value)
                    ->where('status', AccountStatus::Active->value)
                    ->whereNotNull('email_verified_at')),
            ],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
