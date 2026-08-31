<?php

namespace App\Http\Requests\Admin;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;

class IndexAirportTransferSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Airport::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
