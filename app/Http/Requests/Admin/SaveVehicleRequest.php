<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVehicleRequest extends FormRequest
{
    use HandlesImageUploads;

    public function authorize(): bool
    {
        $vehicle = $this->route('vehicle');

        return $vehicle instanceof Vehicle
            ? ($this->user()?->can('update', $vehicle) ?? false)
            : ($this->user()?->can('create', Vehicle::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $vehicle = $this->route('vehicle');
        $vehicleId = $vehicle instanceof Vehicle ? $vehicle->getKey() : null;
        $currentYear = (int) now()->format('Y');

        return [
            'slug' => ['nullable', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', Rule::unique('vehicles', 'slug')->ignore($vehicleId)],
            'registration_plate' => ['required', 'string', 'max:32', Rule::unique('vehicles', 'registration_plate')->ignore($vehicleId)],
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1886', 'max:'.($currentYear + 2)],
            'color' => ['required', 'string', 'max:60'],
            'condition' => ['required', 'string', 'max:40'],
            'vehicle_type' => ['required', 'string', 'max:40', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'fuel_type' => ['required', 'string', 'max:40', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'transmission' => ['required', 'string', 'max:40', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'seating_capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'luggage_capacity' => ['required', 'integer', 'min:0', 'max:100'],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:50000'],
            'catalogue_status' => ['required', Rule::enum(VehicleCatalogueStatus::class)],
            'operational_status' => ['required', Rule::enum(VehicleOperationalStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            // Photographs now arrive as uploaded files. The media rules stay
            // for the API and the importer, but the key is no longer required:
            // SaveVehicle replaces the whole collection when it is present, so
            // a form that stopped sending it would wipe every picture.
            'media' => ['sometimes', 'array', 'max:20'],
            'media.*.url' => ['nullable', 'string', 'max:2048'],
            'media.*.alt_text' => ['nullable', 'string', 'max:255'],
            'media.*.caption' => ['nullable', 'string', 'max:500'],
            'media.*.is_cover' => ['sometimes', 'boolean'],
            'media.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ] + $this->imageRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->imageMessages();
    }

    protected function prepareForValidation(): void
    {
        $media = collect($this->input('media', []))
            ->filter(fn (mixed $medium): bool => is_array($medium) && filled($medium['url'] ?? null))
            ->values()
            ->map(function (array $medium, int $index): array {
                $medium['is_cover'] = filter_var($medium['is_cover'] ?? false, FILTER_VALIDATE_BOOL);
                $medium['sort_order'] = filled($medium['sort_order'] ?? null) ? (int) $medium['sort_order'] : $index;

                return $medium;
            })
            ->all();

        $this->merge(['is_featured' => $this->boolean('is_featured')]);

        // Only when the request actually carried media. Merging an empty array
        // would look identical to "delete every photograph" to SaveVehicle.
        if ($this->has('media')) {
            $this->merge(['media' => $media]);
        }
    }
}
