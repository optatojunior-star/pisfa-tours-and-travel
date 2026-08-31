<?php

namespace App\Http\Requests\VehicleImports;

use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleImportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $currentYear = (int) now()->format('Y');

        return [
            'idempotency_key' => ['required', 'uuid'],
            'make' => ['required', 'string', 'min:2', 'max:60'],
            'model' => ['required', 'string', 'min:1', 'max:80'],
            'year_from' => ['required', 'integer', 'min:1980', 'max:'.($currentYear + 1)],
            'year_to' => ['required', 'integer', 'min:1980', 'max:'.($currentYear + 1), 'gte:year_from'],
            'body_type' => ['required', Rule::enum(VehicleImportBodyType::class)],
            'fuel_type' => ['required', Rule::enum(VehicleImportFuelType::class)],
            'transmission' => ['required', Rule::enum(VehicleImportTransmission::class)],
            'drive_type' => ['required', Rule::enum(VehicleImportDriveType::class)],
            'steering' => ['required', Rule::enum(VehicleImportSteering::class)],
            'engine_capacity_cc' => ['nullable', 'integer', 'min:600', 'max:10000'],
            'origin_country' => [
                'required',
                Rule::in(array_keys((array) config('vehicle_imports.origin_countries', []))),
            ],
            'maximum_mileage_km' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'auction_grade' => ['nullable', 'string', 'max:16'],
            'preferred_colour' => ['nullable', 'string', 'max:40'],
            'units' => ['required', 'integer', 'min:1', 'max:'.config('vehicle_imports.maximum_units', 20)],
            'purpose' => ['required', Rule::in(array_keys((array) config('vehicle_imports.purposes', [])))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'budget' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'budget_currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,40}$/'],
            'acknowledge_request' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'acknowledge_request.accepted' => 'Please acknowledge that this is a sourcing request, not an order.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'make' => trim((string) $this->input('make', '')),
            'model' => trim((string) $this->input('model', '')),
            'origin_country' => strtoupper(trim((string) $this->input('origin_country', ''))),
            'budget' => trim((string) $this->input('budget', '')),
            'budget_currency' => strtoupper(trim((string) $this->input('budget_currency', ''))),
            'contact_name' => trim((string) $this->input('contact_name', '')),
            'contact_email' => mb_strtolower(trim((string) $this->input('contact_email', ''))),
            'contact_phone' => trim((string) $this->input('contact_phone', '')),
        ]);
    }
}
