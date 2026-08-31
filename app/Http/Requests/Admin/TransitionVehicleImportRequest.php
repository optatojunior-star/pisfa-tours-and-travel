<?php

namespace App\Http\Requests\Admin;

use App\Enums\VehicleImportStatus;
use App\Models\VehicleImportOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionVehicleImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('vehicleImport');

        return $order instanceof VehicleImportOrder
            && ($this->user()?->can('transition', $order) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(VehicleImportStatus::class)],
            'reason' => [
                'nullable',
                'string',
                'min:5',
                'max:1000',
                Rule::requiredIf($this->input('status') === VehicleImportStatus::Cancelled->value),
            ],
            'notify_customer' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['notify_customer' => $this->boolean('notify_customer')]);
    }
}
