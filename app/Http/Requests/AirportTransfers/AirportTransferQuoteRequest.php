<?php

namespace App\Http\Requests\AirportTransfers;

use App\Enums\AirportTransferType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AirportTransferQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hasQuoteInput = collect([
            'transfer_type',
            'airport_id',
            'airport_transfer_location_id',
            'vehicle_type',
            'currency',
            'flight_scheduled_at',
            'service_starts_at',
            'passenger_count',
            'luggage_count',
        ])->contains(fn (string $field): bool => $this->input($field) !== null
            && $this->input($field) !== '');

        $requiredForQuote = Rule::requiredIf($hasQuoteInput);

        return [
            'transfer_type' => [$requiredForQuote, 'nullable', Rule::enum(AirportTransferType::class)],
            'airport_id' => [
                $requiredForQuote,
                'nullable',
                'integer',
                Rule::exists('airports', 'id')->where('is_active', true),
            ],
            'airport_transfer_location_id' => [
                $requiredForQuote,
                'nullable',
                'integer',
                Rule::exists('airport_transfer_locations', 'id')->where('is_active', true),
            ],
            // The planner lists every priced vehicle class for a route, so a
            // class is chosen from the results rather than supplied up front.
            'vehicle_type' => [
                'nullable',
                'string',
                'max:40',
                'regex:/\A[\pL\pN][\pL\pN _-]*\z/u',
            ],
            'currency' => [
                $requiredForQuote,
                'nullable',
                Rule::in(config('airport_transfers.currencies', ['UGX', 'USD'])),
            ],
            'flight_scheduled_at' => [$requiredForQuote, 'nullable', 'date_format:Y-m-d\TH:i'],
            'service_starts_at' => [
                Rule::requiredIf($this->input('transfer_type') === AirportTransferType::Dropoff->value),
                'nullable',
                'date_format:Y-m-d\TH:i',
            ],
            'passenger_count' => [
                $requiredForQuote,
                'nullable',
                'integer',
                'min:1',
                'max:'.config('airport_transfers.maximum_passengers', 50),
            ],
            'luggage_count' => [
                $requiredForQuote,
                'nullable',
                'integer',
                'min:0',
                'max:'.config('airport_transfers.maximum_luggage', 100),
            ],
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
            ]) || ! filled($this->input('flight_scheduled_at'))) {
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

            if ($serviceStart->isBefore($earliestStart)) {
                $validator->errors()->add(
                    $this->input('transfer_type') === AirportTransferType::Dropoff->value
                        ? 'service_starts_at'
                        : 'flight_scheduled_at',
                    'Choose a transfer time that satisfies the minimum booking notice.',
                );
            }

            if ($serviceStart->isAfter($latestStart)) {
                $validator->errors()->add(
                    $this->input('transfer_type') === AirportTransferType::Dropoff->value
                        ? 'service_starts_at'
                        : 'flight_scheduled_at',
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vehicle_type' => filled($this->input('vehicle_type'))
                ? trim((string) $this->input('vehicle_type'))
                : null,
            'currency' => filled($this->input('currency'))
                ? strtoupper(trim((string) $this->input('currency')))
                : null,
        ]);
    }
}
