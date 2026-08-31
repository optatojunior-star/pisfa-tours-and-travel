<?php

namespace App\Http\Requests\Admin;

use App\Enums\BookingStage;
use App\Support\Bookings\BookingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the cross-domain booking list.
 *
 * Sort and source are validated against enums rather than passed through,
 * because both end up shaping a query: an unvalidated sort column would be an
 * injection point in the union's ORDER BY.
 */
class UnifiedBookingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdministration() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', Rule::enum(BookingStage::class)],
            'source' => ['nullable', Rule::enum(BookingSource::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['service_date', 'created_at', 'amount'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the range cannot be before its start.',
        ];
    }
}
