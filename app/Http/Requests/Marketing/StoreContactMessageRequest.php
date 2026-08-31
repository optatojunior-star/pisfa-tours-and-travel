<?php

namespace App\Http\Requests\Marketing;

use App\Http\Controllers\Marketing\PublicPageController;
use Illuminate\Foundation\Http\FormRequest;

class StoreContactMessageRequest extends FormRequest
{
    protected $errorBag = 'contact';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'phone' => filled($this->input('phone'))
                ? trim((string) $this->input('phone'))
                : null,
            'service' => filled($this->input('service'))
                ? trim((string) $this->input('service'))
                : null,
            'source' => trim((string) $this->input('source', 'contact')),
            'message' => trim((string) $this->input('message')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'service' => ['nullable', 'string', 'in:'.implode(',', array_keys(PublicPageController::SERVICES))],
            'source' => ['required', 'string', 'in:contact,quotation'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid telephone number using digits and standard telephone symbols.',
            'message.min' => 'Please provide at least 20 characters so our team can understand your request.',
        ];
    }
}
