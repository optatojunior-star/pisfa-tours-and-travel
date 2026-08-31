<?php

namespace App\Http\Requests\CarHire;

use App\Enums\HireMode;
use App\Models\CarHireBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCarHireBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CarHireBooking::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'hire_mode' => ['required', Rule::enum(HireMode::class)],
            'currency' => ['required', Rule::in(config('car_hire.currencies', ['UGX', 'USD']))],
            'pickup_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'return_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'pickup_location' => ['required', 'string', 'max:500'],
            'return_location' => ['nullable', 'string', 'max:500'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,40}$/'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'acknowledge_request' => ['accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['pickup_at', 'return_at'])) {
                return;
            }

            $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
            $pickup = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $this->input('pickup_at'), $timezone);
            $return = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $this->input('return_at'), $timezone);

            if ($pickup === false || $return === false) {
                return;
            }

            $earliestPickup = CarbonImmutable::now($timezone)
                ->addHours((int) config('car_hire.minimum_notice_hours', 2));

            if ($pickup->isBefore($earliestPickup)) {
                $validator->errors()->add('pickup_at', 'Choose a pickup time that satisfies the minimum booking notice.');
            }

            if (! $return->isAfter($pickup)) {
                $validator->errors()->add('return_at', 'The return time must be after the pickup time.');

                return;
            }

            if ($return->isAfter($pickup->addDays((int) config('car_hire.maximum_hire_days', 90)))) {
                $validator->errors()->add('return_at', 'The hire period exceeds the maximum allowed duration.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'acknowledge_request.accepted' => 'Please acknowledge that this submits a hire request and does not collect payment.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper(trim((string) $this->input('currency', '')))]);
    }
}
