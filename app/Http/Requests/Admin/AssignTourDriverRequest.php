<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AssignTourDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('tourBooking');

        return $booking !== null && ($this->user()?->can('assign', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
