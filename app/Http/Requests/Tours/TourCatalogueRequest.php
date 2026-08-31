<?php

namespace App\Http\Requests\Tours;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class TourCatalogueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.now(config('pisfa.business_timezone', 'Africa/Kampala'))->toDateString(),
            ],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:'.config('tours.maximum_booking_travelers', 50)],
            'duration_min' => ['nullable', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90)],
            'duration_max' => ['nullable', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90), 'gte:duration_min'],
            'min_price' => ['bail', 'nullable', 'string', 'max:24', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'max_price' => ['bail', 'nullable', 'string', 'max:24', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'currency' => [
                'nullable',
                'required_with:min_price,max_price',
                'required_if:sort,price_asc',
                Rule::in(config('tours.currencies', ['UGX', 'USD'])),
            ],
            'sort' => ['nullable', Rule::in(['recommended', 'earliest', 'price_asc', 'newest', 'duration'])],
            'per_page' => ['nullable', Rule::in([12, 24, 36, '12', '24', '36'])],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $currency = strtoupper((string) $this->input('currency', ''));
            $supportedCurrencies = config('tours.currencies', ['UGX', 'USD']);

            if (! in_array($currency, $supportedCurrencies, true)) {
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
        }];
    }
}
