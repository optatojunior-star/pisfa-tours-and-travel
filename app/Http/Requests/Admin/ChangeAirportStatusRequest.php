<?php

namespace App\Http\Requests\Admin;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;

class ChangeAirportStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $airport = $this->route('airport');

        return $airport instanceof Airport && ($this->user()?->can('update', $airport) ?? false);
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
