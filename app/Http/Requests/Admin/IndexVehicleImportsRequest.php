<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleImportStatus;
use App\Models\VehicleImportOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexVehicleImportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', VehicleImportOrder::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(VehicleImportStatus::class)],
            'queue' => ['nullable', Rule::in(['open', 'mine', 'unassigned', 'awaiting_deposit'])],
            'origin_country' => [
                'nullable',
                Rule::in(array_keys((array) config('vehicle_imports.origin_countries', []))),
            ],
        ];
    }
}
