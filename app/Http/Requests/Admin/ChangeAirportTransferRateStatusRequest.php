<?php

namespace App\Http\Requests\Admin;

use App\Models\AirportTransferRate;
use Illuminate\Foundation\Http\FormRequest;

class ChangeAirportTransferRateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rate = $this->route('airportTransferRate');

        return $rate instanceof AirportTransferRate && ($this->user()?->can('update', $rate) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
