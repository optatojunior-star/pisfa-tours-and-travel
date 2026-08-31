<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ChangeVehicleStatusRequest extends FormRequest
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
            'catalogue_status' => ['sometimes', Rule::enum(VehicleCatalogueStatus::class)],
            'operational_status' => ['sometimes', Rule::enum(VehicleOperationalStatus::class)],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [function ($validator): void {
            if (! $this->hasAny(['catalogue_status', 'operational_status'])) {
                $validator->errors()->add('catalogue_status', 'Select a catalogue or operational status.');
            }
        }];
    }
}
