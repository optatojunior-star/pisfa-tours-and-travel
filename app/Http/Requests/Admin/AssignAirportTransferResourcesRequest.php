<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\AirportTransferBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignAirportTransferResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('airportTransferBooking');

        return $booking instanceof AirportTransferBooking
            && ($this->user()?->can('assign', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where(
                    'operational_status',
                    VehicleOperationalStatus::Available->value,
                ),
            ],
            'driver_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::Driver->value)
                    ->where('status', AccountStatus::Active->value)
                    ->whereNotNull('email_verified_at')),
            ],
            'replacement_reason' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $booking = $this->route('airportTransferBooking');

            if (! $booking instanceof AirportTransferBooking
                || $booking->assigned_vehicle_id === null
                || $booking->assigned_driver_user_id === null) {
                return;
            }

            $samePair = (int) $this->input('vehicle_id') === (int) $booking->assigned_vehicle_id
                && (int) $this->input('driver_user_id') === (int) $booking->assigned_driver_user_id;

            if (! $samePair && ! filled($this->input('replacement_reason'))) {
                $validator->errors()->add(
                    'replacement_reason',
                    'Enter a reason when replacing the active driver and vehicle assignment.',
                );
            }
        }];
    }
}
