<?php

namespace App\Http\Requests\Admin;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAirportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $airport = $this->route('airport');

        return $airport instanceof Airport
            ? ($this->user()?->can('update', $airport) ?? false)
            : ($this->user()?->can('create', Airport::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $airport = $this->route('airport');

        return [
            'code' => [
                'required',
                'string',
                'min:3',
                'max:8',
                'regex:/\A[A-Z0-9]+\z/',
                Rule::unique('airports', 'code')->ignore($airport),
            ],
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'city' => ['required', 'string', 'min:2', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/\A[A-Z]{2}\z/'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'terminal_information' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code', ''))),
            'name' => trim((string) $this->input('name', '')),
            'city' => trim((string) $this->input('city', '')),
            'country_code' => strtoupper(trim((string) $this->input('country_code', ''))),
            'timezone' => trim((string) $this->input('timezone', '')),
            'terminal_information' => filled($this->input('terminal_information'))
                ? trim((string) $this->input('terminal_information'))
                : null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
