<?php

namespace App\Http\Requests\Drivers;

use App\Models\DriverTrip;
use Illuminate\Foundation\Http\FormRequest;

class CloseDriverTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        $trip = $this->route('driverTrip');
        $user = $this->user();

        return $trip instanceof DriverTrip
            && $user !== null
            && (int) $trip->driver_user_id === (int) $user->getKey();
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
