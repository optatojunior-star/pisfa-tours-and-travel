<?php

namespace App\Http\Requests\Admin;

use App\Models\TourDeparture;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SaveTourDepartureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $departure = $this->route('tourDeparture');

        return $departure instanceof TourDeparture
            ? ($this->user()?->can('update', $departure) ?? false)
            : ($this->user()?->can('create', [TourDeparture::class, $this->route('tourPackage')]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'cancellation_cutoff_at' => ['required', 'date', 'before:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_departure_capacity', 500)],
            'price_override' => [
                'nullable', 'string', 'max:30',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    try {
                        if (Money::parse((string) $value, (string) $this->input('currency')) < 1) {
                            $fail('The departure price must be greater than zero.');
                        }
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'currency' => ['required', Rule::in(config('tours.currencies', ['UGX', 'USD']))],
            'meeting_point' => ['nullable', 'string', 'max:300'],
            'customer_notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['currency' => strtoupper((string) $this->input('currency', 'UGX'))]);
    }
}
