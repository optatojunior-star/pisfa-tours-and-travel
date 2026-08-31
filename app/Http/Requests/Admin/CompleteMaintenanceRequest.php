<?php

namespace App\Http\Requests\Admin;

use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Closing a job records its real cost and odometer reading. Both are required:
 * a completed record with neither is not evidence of anything.
 */
class CompleteMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = $this->route('maintenance');
        $vehicle = $record instanceof VehicleMaintenanceRecord ? $record->vehicle : null;

        return $vehicle instanceof Vehicle
            && ($this->user()?->can('manageFleet', $vehicle) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            'cost' => ['required', 'string', 'max:24'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'next_due_on' => ['nullable', 'date', 'after:today'],
            'next_due_odometer_km' => ['nullable', 'integer', 'min:1', 'max:5000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'odometer_km.required' => 'Record the odometer reading taken when the work was done.',
            'cost.required' => 'Record what the work cost, or zero if it was under warranty.',
        ];
    }
}
