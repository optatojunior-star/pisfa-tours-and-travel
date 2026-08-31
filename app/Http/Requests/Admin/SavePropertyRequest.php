<?php

namespace App\Http\Requests\Admin;

use App\Enums\PropertyType;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = $this->route('property');

        if ($property instanceof Property) {
            return $this->user()?->can('update', $property) ?? false;
        }

        return $this->user()?->can('create', Property::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'region' => ['required', 'string', 'min:2', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'summary' => ['required', 'string', 'min:20', 'max:400'],
            'description' => ['required', 'string', 'min:50', 'max:8000'],
            'directions' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'check_in_from' => ['required', 'date_format:H:i'],
            'check_out_by' => ['required', 'date_format:H:i'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:2160'],
            'is_featured' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'check_in_from' => 'check-in time',
            'check_out_by' => 'check-out time',
            'cancellation_cutoff_hours' => 'free-cancellation window',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'summary.min' => 'The summary is the line guests read first — give it a sentence.',
            'description.min' => 'Describe the property properly; guests decide on this text.',
        ];
    }
}
