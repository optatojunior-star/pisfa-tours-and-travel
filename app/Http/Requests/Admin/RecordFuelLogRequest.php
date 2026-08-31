<?php

namespace App\Http\Requests\Admin;

use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordFuelLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle
            && ($this->user()?->can('manageFleet', $vehicle) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filled_at' => ['required', 'date', 'before_or_equal:now'],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            'litres' => ['required', 'numeric', 'min:0.01', 'max:2000'],
            'cost' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'station' => ['nullable', 'string', 'max:180'],
            'is_full_tank' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'filled_at.before_or_equal' => 'A refuelling cannot be recorded in the future.',
        ];
    }
}
