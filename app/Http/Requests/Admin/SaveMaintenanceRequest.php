<?php

namespace App\Http\Requests\Admin;

use App\Enums\MaintenanceType;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        if (! $vehicle instanceof Vehicle) {
            $record = $this->route('maintenance');
            $vehicle = $record instanceof VehicleMaintenanceRecord ? $record->vehicle : null;
        }

        return $vehicle instanceof Vehicle
            && ($this->user()?->can('manageFleet', $vehicle) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(MaintenanceType::class)],
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'scheduled_for' => ['nullable', 'date'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
