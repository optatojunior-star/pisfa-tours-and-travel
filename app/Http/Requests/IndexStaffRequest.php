<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\StaffRoles;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin)
            && $this->user()->isActive();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(StaffRoles::values())],
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
        ];
    }
}
