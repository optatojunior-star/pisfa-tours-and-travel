<?php

namespace App\Http\Requests\Portal;

use App\Enums\UserRole;
use App\Support\Portal\ActivityKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isActive() && $user->hasRole(UserRole::Customer);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            // Validated against the enum because it selects a projection; an
            // unvalidated value would reach a query.
            'kind' => ['nullable', Rule::enum(ActivityKind::class)],
        ];
    }
}
