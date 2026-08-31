<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexVehiclesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Vehicle::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'catalogue_status' => ['nullable', Rule::enum(VehicleCatalogueStatus::class)],
            'operational_status' => ['nullable', Rule::enum(VehicleOperationalStatus::class)],
            'vehicle_type' => ['nullable', 'string', 'max:40'],
        ];
    }
}
