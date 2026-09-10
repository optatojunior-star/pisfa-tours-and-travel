<?php

namespace App\Http\Requests\CarHire;

use App\Enums\HireMode;
use App\Support\Money;
use App\Support\VehicleSpecification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class CarHireCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hasPriceComparison = filled($this->input('min_price'))
            || filled($this->input('max_price'))
            || in_array($this->input('sort'), ['price_asc', 'price_desc'], true);

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'pickup_at' => ['nullable', 'required_with:return_at', 'date_format:Y-m-d\TH:i'],
            'return_at' => ['nullable', 'required_with:pickup_at', 'date_format:Y-m-d\TH:i'],
            'hire_mode' => [
                'nullable',
                Rule::requiredIf($hasPriceComparison),
                Rule::enum(HireMode::class),
            ],
            'vehicle_type' => ['nullable', 'string', 'max:40', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'transmission' => ['nullable', 'string', 'max:40', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            // "Is it 4WD" is the question a customer heading for Kidepo or
            // Bwindi asks first, and until now the catalogue could not answer
            // it at all — the column did not exist.
            'drive_type' => ['nullable', Rule::in(array_keys(VehicleSpecification::driveTypes()))],
            'min_seats' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_price' => ['bail', 'nullable', 'string', 'max:24', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'max_price' => ['bail', 'nullable', 'string', 'max:24', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'currency' => [
                'nullable',
                Rule::requiredIf($hasPriceComparison),
                Rule::in(config('car_hire.currencies', ['UGX', 'USD'])),
            ],
            'sort' => ['nullable', Rule::in(['recommended', 'price_asc', 'price_desc', 'newest', 'seats_desc'])],
            'per_page' => ['nullable', Rule::in([12, 24, 36, '12', '24', '36'])],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateHireWindow($validator);
            $this->validatePriceRange($validator);
        }];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
        }
    }

    private function validateHireWindow(Validator $validator): void
    {
        if (! filled($this->input('pickup_at'))
            || ! filled($this->input('return_at'))
            || $validator->errors()->hasAny(['pickup_at', 'return_at'])) {
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
    }

    private function validatePriceRange(Validator $validator): void
    {
        $currency = strtoupper((string) $this->input('currency', ''));

        if (! in_array($currency, config('car_hire.currencies', ['UGX', 'USD']), true)) {
            return;
        }

        $parsed = [];

        foreach (['min_price', 'max_price'] as $field) {
            if (! filled($this->input($field)) || $validator->errors()->has($field)) {
                continue;
            }

            try {
                $parsed[$field] = Money::parse((string) $this->input($field), $currency);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add($field, $exception->getMessage());
            }
        }

        if (isset($parsed['min_price'], $parsed['max_price'])
            && $parsed['max_price'] < $parsed['min_price']) {
            $validator->errors()->add('max_price', 'The maximum price must be at least the minimum price.');
        }
    }
}
