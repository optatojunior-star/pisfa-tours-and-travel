<?php

namespace App\Http\Requests\Admin;

use App\Models\AirportTransferLocation;
use Illuminate\Foundation\Http\FormRequest;

class ChangeAirportTransferLocationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('airportTransferLocation');

        return $location instanceof AirportTransferLocation
            && ($this->user()?->can('update', $location) ?? false);
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
