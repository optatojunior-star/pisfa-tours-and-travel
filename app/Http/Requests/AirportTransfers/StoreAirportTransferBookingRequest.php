<?php

namespace App\Http\Requests\AirportTransfers;

use App\Enums\AirportTransferType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAirportTransferBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'transfer_type' => ['required', Rule::enum(AirportTransferType::class)],
            'airport_id' => [
                'required',
                'integer',
                Rule::exists('airports', 'id')->where('is_active', true),
            ],
            'airport_transfer_location_id' => [
                'required',
                'integer',
                Rule::exists('airport_transfer_locations', 'id')->where('is_active', true),
            ],
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
            'flight_number' => ['nullable', 'string', 'max:32', 'regex:/\A[\pL\pN][\pL\pN .\/_-]*\z/u'],
            'flight_scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'service_starts_at' => [
                Rule::requiredIf($this->input('transfer_type') === AirportTransferType::Dropoff->value),
                'nullable',
                'date_format:Y-m-d\TH:i',
            ],
            'passenger_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('airport_transfers.maximum_passengers', 50),
            ],
            'luggage_count' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('airport_transfers.maximum_luggage', 100),
            ],
            'service_address' => ['required', 'string', 'min:3', 'max:500'],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,40}$/'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'acknowledge_request' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny([
                'transfer_type',
                'flight_scheduled_at',
                'service_starts_at',
            ])) {
                return;
            }

            $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
            $flight = CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) $this->input('flight_scheduled_at'),
                $timezone,
            );
            $serviceStart = filled($this->input('service_starts_at'))
                ? CarbonImmutable::createFromFormat(
                    '!Y-m-d\TH:i',
                    (string) $this->input('service_starts_at'),
                    $timezone,
                )
                : $flight;

            if ($flight === false || $serviceStart === false) {
                return;
            }

            $now = CarbonImmutable::now($timezone);
            $earliestStart = $now->addHours((int) config('airport_transfers.minimum_notice_hours', 2));
            $latestStart = $now->addDays((int) config('airport_transfers.maximum_advance_days', 365));
            $timeField = $this->input('transfer_type') === AirportTransferType::Dropoff->value
                ? 'service_starts_at'
                : 'flight_scheduled_at';

            if ($serviceStart->isBefore($earliestStart)) {
                $validator->errors()->add(
                    $timeField,
                    'Choose a transfer time that satisfies the minimum booking notice.',
                );
            }

            if ($serviceStart->isAfter($latestStart)) {
                $validator->errors()->add(
                    $timeField,
                    'The transfer date is beyond the supported advance-booking window.',
                );
            }

            if ($this->input('transfer_type') === AirportTransferType::Dropoff->value
                && ! $serviceStart->isBefore($flight)) {
                $validator->errors()->add(
                    'service_starts_at',
                    'For an airport drop-off, the pickup time must be before the scheduled flight departure.',
                );
            }
        }];
    }

    public function messages(): array
    {
        return [
            'acknowledge_request.accepted' => 'Please acknowledge that this submits a transfer request and does not collect payment.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vehicle_type' => trim((string) $this->input('vehicle_type', '')),
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
            'flight_number' => filled($this->input('flight_number'))
                ? strtoupper(trim((string) $this->input('flight_number')))
                : null,
            'service_address' => trim((string) $this->input('service_address', '')),
            'contact_name' => trim((string) $this->input('contact_name', '')),
            'contact_email' => mb_strtolower(trim((string) $this->input('contact_email', ''))),
            'contact_phone' => trim((string) $this->input('contact_phone', '')),
            'special_requests' => filled($this->input('special_requests'))
                ? trim((string) $this->input('special_requests'))
                : null,
        ]);
    }
}
