<?php

namespace App\Http\Requests\Admin;

use App\Models\TourCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTourCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('tourCategory');

        return $category instanceof TourCategory
            ? ($this->user()?->can('update', $category) ?? false)
            : ($this->user()?->can('create', TourCategory::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('tourCategory');

        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tour_categories', 'slug')->ignore($category),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
