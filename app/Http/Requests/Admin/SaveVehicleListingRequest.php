<?php

namespace App\Http\Requests\Admin;

use App\Models\VehicleListing;
use App\Support\VehicleSpecification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape and authorisation only.
 *
 * The money and the business rules — a price above zero, a vehicle that is not
 * already listed or out on hire — belong to SaveVehicleListing, which enforces
 * them under a lock. This request exists so the form reports its own mistakes
 * before the action has to.
 */
class SaveVehicleListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $listing = $this->route('listing');

        if ($listing instanceof VehicleListing) {
            return $this->user()?->can('update', $listing) ?? false;
        }

        return $this->user()?->can('create', VehicleListing::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $currentYear = (int) now()->format('Y');
        $route = $this->route('listing');
        $listing = $route instanceof VehicleListing ? $route : null;

        return [
            'title' => ['required', 'string', 'min:4', 'max:200'],
            'make' => ['required', 'string', 'min:2', 'max:60'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'year' => ['required', 'integer', 'min:1950', 'max:'.($currentYear + 1)],
            // Chosen from the shared vehicle vocabulary, not typed. The
            // listing's own current value stays acceptable so stock entered
            // before the lists existed is still editable.
            'body_type' => ['nullable', 'string', 'max:32', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::bodyTypes(), $listing?->body_type),
            )],
            'fuel_type' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::fuelTypes(), $listing?->fuel_type),
            )],
            'transmission' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::transmissions(), $listing?->transmission),
            )],
            'drive_type' => ['nullable', 'string', 'max:16', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::driveTypes(), $listing?->drive_type),
            )],
            'engine_cc' => ['nullable', 'integer', 'min:50', 'max:20000'],
            'colour' => ['nullable', 'string', 'max:40'],
            'mileage_km' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'seating_capacity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'condition' => ['nullable', 'string', 'max:24', Rule::in(
                VehicleSpecification::allowedValues(VehicleSpecification::conditions(), $listing?->condition),
            )],
            'description' => ['required', 'string', 'min:30', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'asking_price' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'is_negotiable' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            // Only meaningful on create; ignored on update, because moving a
            // listing onto a different vehicle would rewrite what was sold.
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],

            // Photographs. The real inspection happens in FileInspector, which
            // reads the file's actual content; these rules only reject the
            // obviously wrong before a large body is loaded.
            'images' => ['nullable', 'array', 'max:12'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('documents.images.maximum_kilobytes', 5120)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'asking_price' => 'asking price',
            'vehicle_id' => 'fleet vehicle',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.min' => 'Describe the vehicle properly — buyers decide on this text.',
        ];
    }
}
