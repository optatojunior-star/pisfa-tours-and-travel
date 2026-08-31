<?php

namespace App\Http\Requests\Drivers;

use App\Enums\InspectionPhase;
use App\Enums\UserRole;
use App\Support\Fleet\InspectionChecklist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::Driver) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phase' => ['required', Rule::enum(InspectionPhase::class)],
            'odometer_km' => ['required', 'integer', 'min:0', 'max:5000000'],
            'answers' => ['required', 'array'],
            // Only known answers are accepted; the action normalises the rest
            // away so a stored inspection always has the same shape.
            'answers.*' => ['nullable', Rule::in(['ok', 'defect', 'not_applicable'])],
            'defect_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'odometer_km.required' => 'Read the odometer before you sign the check off.',
        ];
    }

    /** @return array<string, array{label: string, critical: bool}> */
    public function checklist(): array
    {
        return InspectionChecklist::ITEMS;
    }
}
