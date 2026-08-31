<?php

namespace App\Http\Requests\FlightInquiries;

use App\Enums\FlightInquiryScope;
use App\Enums\FlightTravelClass;
use App\Enums\FlightTripType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFlightInquiryRequest extends FormRequest
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
            'scope' => ['required', Rule::enum(FlightInquiryScope::class)],
            'trip_type' => ['required', Rule::enum(FlightTripType::class)],
            'travel_class' => ['required', Rule::enum(FlightTravelClass::class)],
            'origin' => ['required', 'string', 'min:2', 'max:120'],
            'destination' => ['required', 'string', 'min:2', 'max:120'],
            'outbound_on' => ['required', 'date_format:Y-m-d'],
            'return_on' => [
                Rule::requiredIf($this->input('trip_type') === FlightTripType::Return->value),
                'nullable',
                'date_format:Y-m-d',
                'after:outbound_on',
            ],
            'passenger_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('flight_inquiries.maximum_passengers', 50),
            ],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,40}$/'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'acknowledge_enquiry' => ['required', 'accepted'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('outbound_on')) {
                return;
            }

            $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
            $outbound = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                (string) $this->input('outbound_on'),
                $timezone,
            );

            if ($outbound === false) {
                return;
            }

            $today = CarbonImmutable::now($timezone)->startOfDay();
            $earliest = $today->addDays((int) config('flight_inquiries.minimum_notice_days', 1));
            $latest = $today->addDays((int) config('flight_inquiries.maximum_advance_days', 365));

            if ($outbound->isBefore($earliest)) {
                $validator->errors()->add(
                    'outbound_on',
                    'Choose an outbound date that meets the minimum enquiry notice.',
                );
            }

            if ($outbound->isAfter($latest)) {
                $validator->errors()->add(
                    'outbound_on',
                    'The outbound date is beyond the supported planning window.',
                );
            }

            if (! $validator->errors()->has('destination')
                && mb_strtolower((string) $this->input('origin')) === mb_strtolower((string) $this->input('destination'))) {
                $validator->errors()->add('destination', 'The destination must differ from the origin.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'acknowledge_enquiry.accepted' => 'Please acknowledge that this is a fare enquiry and does not reserve a seat.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'origin' => trim((string) $this->input('origin', '')),
            'destination' => trim((string) $this->input('destination', '')),
            'contact_name' => trim((string) $this->input('contact_name', '')),
            'contact_email' => mb_strtolower(trim((string) $this->input('contact_email', ''))),
            'contact_phone' => trim((string) $this->input('contact_phone', '')),
            // A one-way enquiry must not carry a stale return date from the form.
            'return_on' => $this->input('trip_type') === FlightTripType::Return->value
                && filled($this->input('return_on'))
                    ? $this->input('return_on')
                    : null,
            'notes' => filled($this->input('notes')) ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
