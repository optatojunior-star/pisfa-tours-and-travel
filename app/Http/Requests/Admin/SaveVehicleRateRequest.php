<?php

namespace App\Http\Requests\Admin;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle && ($this->user()?->can('update', $vehicle) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency' => ['required', Rule::in(config('car_hire.currencies', ['UGX', 'USD']))],
            'self_drive_daily' => ['nullable', 'string', 'max:40', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'with_driver_daily' => ['nullable', 'string', 'max:40', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'security_deposit' => ['nullable', 'string', 'max:40', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date_format:Y-m-d\TH:i'],
            'effective_until' => ['nullable', 'date_format:Y-m-d\TH:i', 'after:effective_from'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
