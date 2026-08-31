<?php

namespace App\Http\Requests\Admin;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferType;
use App\Models\AirportTransferBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAirportTransferBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AirportTransferBooking::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(AirportTransferBookingStatus::class)],
            'transfer_type' => ['nullable', Rule::enum(AirportTransferType::class)],
            'airport_id' => ['nullable', 'integer', Rule::exists('airports', 'id')],
            'airport_transfer_location_id' => [
                'nullable',
                'integer',
                Rule::exists('airport_transfer_locations', 'id'),
            ],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
