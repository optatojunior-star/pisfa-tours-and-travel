<?php

namespace App\Http\Requests\Corporate;

use App\Models\GroupBooking;
use App\Support\ServiceCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGroupBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('group');

        if ($booking instanceof GroupBooking) {
            return $this->user()?->can('update', $booking) ?? false;
        }

        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'corporate_account_id' => ['nullable', 'integer', 'exists:corporate_accounts,id'],
            'title' => ['required', 'string', 'min:4', 'max:200'],
            'service_kind' => ['required', Rule::in(ServiceCatalogue::keys())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'headcount' => [
                'required',
                'integer',
                'min:'.(int) config('corporate.minimum_group_size', 2),
                'max:'.(int) config('corporate.maximum_group_size', 500),
            ],
            'pickup_location' => ['nullable', 'string', 'max:500'],
            'destination' => ['nullable', 'string', 'max:500'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'headcount.min' => 'A group is at least two people — one traveller is an ordinary booking.',
            'ends_on.after_or_equal' => 'A trip cannot finish before it starts.',
        ];
    }
}
