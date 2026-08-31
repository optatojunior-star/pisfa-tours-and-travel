<?php

namespace App\Http\Requests\Admin;

use App\Models\TourPackage;
use App\Support\Money;
use App\Support\PublicMediaUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class SaveTourPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $package = $this->route('tourPackage');

        return $package instanceof TourPackage
            ? ($this->user()?->can('update', $package) ?? false)
            : ($this->user()?->can('create', TourPackage::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $package = $this->route('tourPackage');

        return [
            'category_id' => ['required', 'integer', 'exists:tour_categories,id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => [
                'nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tour_packages', 'slug')->ignore($package),
            ],
            'destination' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:10000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90)],
            'base_price' => [
                'required', 'string', 'max:30',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        if (Money::parse((string) $value, (string) $this->input('currency')) < 1) {
                            $fail('The base price must be greater than zero.');
                        }
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            'currency' => ['required', Rule::in(config('tours.currencies', ['UGX', 'USD']))],
            'min_travelers' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_booking_travelers', 50)],
            'max_travelers' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_booking_travelers', 50), 'gte:min_travelers'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'is_featured' => ['sometimes', 'boolean'],
            'media' => ['nullable', 'array', 'max:20'],
            'media.*.url' => [
                'nullable',
                'string',
                'max:2048',
                'required_with:media.*.alt_text',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && ! PublicMediaUrl::isSafe((string) $value)) {
                        $fail('Use an HTTPS URL or an application-relative media path.');
                    }
                },
            ],
            'media.*.alt_text' => ['nullable', 'required_with:media.*.url', 'string', 'max:180'],
            'media.*.caption' => ['nullable', 'string', 'max:300'],
            'media.*.is_cover' => ['sometimes', 'boolean'],
            'itinerary' => ['required', 'array', 'min:1', 'max:'.config('tours.maximum_duration_days', 90)],
            'itinerary.*.day_number' => ['required', 'integer', 'min:1', 'max:'.config('tours.maximum_duration_days', 90), 'distinct'],
            'itinerary.*.title' => ['required', 'string', 'max:180'],
            'itinerary.*.description' => ['required', 'string', 'max:3000'],
            'itinerary.*.activities' => ['nullable', 'string', 'max:1000'],
            'itinerary.*.meals' => ['nullable', 'string', 'max:300'],
            'itinerary.*.overnight_location' => ['nullable', 'string', 'max:300'],
            'inclusions' => ['required', 'array', 'min:1', 'max:50'],
            'inclusions.*' => ['required', 'string', 'max:300', 'distinct'],
            'exclusions' => ['nullable', 'array', 'max:50'],
            'exclusions.*' => ['nullable', 'string', 'max:300', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $media = array_values(array_filter(
            (array) $this->input('media', []),
            static fn (mixed $row): bool => is_array($row)
                && collect($row)->except(['is_cover'])->contains(fn (mixed $value): bool => filled($value)),
        ));
        $itinerary = array_values(array_filter(
            (array) $this->input('itinerary', []),
            static fn (mixed $row): bool => is_array($row)
                && collect($row)->except(['day_number'])->contains(fn (mixed $value): bool => filled($value)),
        ));
        $inclusions = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $this->input('inclusions', [])),
            static fn (string $value): bool => $value !== '',
        ));
        $exclusions = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) $this->input('exclusions', [])),
            static fn (string $value): bool => $value !== '',
        ));

        $this->merge([
            'currency' => strtoupper((string) $this->input('currency', 'UGX')),
            'is_featured' => $this->boolean('is_featured'),
            'media' => $media,
            'itinerary' => $itinerary,
            'inclusions' => $inclusions,
            'exclusions' => $exclusions,
        ]);
    }
}
