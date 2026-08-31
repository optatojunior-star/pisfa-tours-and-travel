<?php

namespace App\Http\Requests\Drivers;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StartDriverTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership of the specific assignment is proved by the controller's
        // scoped lookup and re-proved against the locked row in the action.
        return $this->user()?->hasRole(UserRole::Driver) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'driver_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
