<?php

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Models\TeamMember;
use Illuminate\Foundation\Http\FormRequest;

class SaveTeamMemberRequest extends FormRequest
{
    use HandlesImageUploads;

    public function authorize(): bool
    {
        $member = $this->route('team');

        return $member instanceof TeamMember
            ? ($this->user()?->can('update', $member) ?? false)
            : ($this->user()?->can('create', TeamMember::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'role_title' => ['required', 'string', 'min:2', 'max:120'],
            'summary' => ['nullable', 'string', 'max:400'],
            'biography' => ['nullable', 'string', 'max:5000'],

            // Contact details are optional and public once published, which is
            // why they are not carried over from a staff account automatically:
            // putting somebody's personal number on the website has to be a
            // decision, not a side effect.
            'email' => ['nullable', 'email:rfc,dns', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ] + $this->imageRules('images', 3);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['role_title' => 'role'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->imageMessages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['sort_order' => (int) $this->input('sort_order', 0)]);
    }
}
