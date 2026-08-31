<?php

namespace App\Http\Requests\Admin;

use App\Models\AirportTransferBooking;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleAirportTransferBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('airportTransferBooking');

        return $booking instanceof AirportTransferBooking
            && ($this->user()?->can('update', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'flight_scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'flight_number' => ['nullable', 'string', 'max:32', 'regex:/\A[\pL\pN][\pL\pN .\/_-]*\z/u'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'flight_number' => filled($this->input('flight_number'))
                ? strtoupper(trim((string) $this->input('flight_number')))
                : null,
        ]);
    }
}
