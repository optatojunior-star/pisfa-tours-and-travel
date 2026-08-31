<?php

namespace App\Http\Requests\Admin;

use App\Enums\AirportTransferType;
use App\Models\AirportTransferRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveAirportTransferRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AirportTransferRate::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'airport_id' => ['required', 'integer', Rule::exists('airports', 'id')],
            'airport_transfer_location_id' => [
                'required',
                'integer',
                Rule::exists('airport_transfer_locations', 'id'),
            ],
            'transfer_type' => ['required', Rule::enum(AirportTransferType::class)],
            'vehicle_type' => [
                'required',
                'string',
                'max:40',
                'regex:/\A[\pL\pN][\pL\pN _-]*\z/u',
            ],
            'currency' => [
                'required',
                Rule::in(config('airport_transfers.currencies', ['UGX', 'USD'])),
            ],
            'passenger_capacity' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('airport_transfers.maximum_passengers', 50),
            ],
            'luggage_capacity' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('airport_transfers.maximum_luggage', 100),
            ],
            'amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/', 'max:24'],
            'estimated_duration_minutes' => [
                'required',
                'integer',
                'min:'.config('airport_transfers.rate_duration.minimum_minutes', 15),
                'max:'.config('airport_transfers.rate_duration.maximum_minutes', 1440),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'effective_until' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:effective_from'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('amount')
                && preg_match('/\A0+(?:\.0+)?\z/', (string) $this->input('amount')) === 1) {
                $validator->errors()->add('amount', 'Enter an amount greater than zero.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vehicle_type' => strtolower(trim((string) $this->input('vehicle_type', ''))),
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
            'amount' => trim((string) $this->input('amount', '')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
