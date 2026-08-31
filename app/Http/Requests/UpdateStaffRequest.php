<?php

namespace App\Http\Requests;

use App\Enums\AccountStatus;
use App\Enums\StaffRoles;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
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
            'role' => ['required', Rule::in(StaffRoles::values())],
            'status' => ['required', Rule::enum(AccountStatus::class)],
            'two_factor_required' => ['sometimes', 'boolean'],
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }

    public function status(): AccountStatus
    {
        return AccountStatus::from($this->string('status')->toString());
    }
}
