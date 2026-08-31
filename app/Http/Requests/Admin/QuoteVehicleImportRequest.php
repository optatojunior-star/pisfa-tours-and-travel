<?php

namespace App\Http\Requests\Admin;

use App\Models\VehicleImportOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteVehicleImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('vehicleImport');

        return $order instanceof VehicleImportOrder
            && ($this->user()?->can('quote', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'total_price' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'deposit' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'estimated_arrival_on' => ['required', 'date_format:Y-m-d'],
            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'total_price' => trim((string) $this->input('total_price', '')),
            'deposit' => trim((string) $this->input('deposit', '')),
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
        ]);
    }
}
