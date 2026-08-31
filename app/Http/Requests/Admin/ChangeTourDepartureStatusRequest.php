<?php

namespace App\Http\Requests\Admin;

use App\Enums\TourDepartureStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTourDepartureStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $departure = $this->route('tourDeparture');

        return $departure !== null && ($this->user()?->can('changeStatus', $departure) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TourDepartureStatus::class)],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('status') === TourDepartureStatus::Cancelled->value)],
        ];
    }
}
