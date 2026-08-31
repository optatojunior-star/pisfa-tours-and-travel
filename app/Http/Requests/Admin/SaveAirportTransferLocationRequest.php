<?php

namespace App\Http\Requests\Admin;

use App\Models\AirportTransferLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAirportTransferLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('airportTransferLocation');

        return $location instanceof AirportTransferLocation
            ? ($this->user()?->can('update', $location) ?? false)
            : ($this->user()?->can('create', AirportTransferLocation::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $location = $this->route('airportTransferLocation');

        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'slug' => [
                'required',
                'string',
                'max:200',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('airport_transfer_locations', 'slug')->ignore($location),
            ],
            'region' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'slug' => str((string) $this->input('slug', $this->input('name', '')))->slug()->toString(),
            'region' => trim((string) $this->input('region', '')),
            'description' => filled($this->input('description'))
                ? trim((string) $this->input('description'))
                : null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
