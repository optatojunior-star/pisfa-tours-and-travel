<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class StartChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Open to the public: a visitor may start a chat without an account.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $guest = $this->user() === null;

        return [
            // A signed-in customer's identity comes from their account, never
            // from the form.
            'contact_name' => [$guest ? 'required' : 'nullable', 'string', 'min:2', 'max:180'],
            'contact_email' => [$guest ? 'required' : 'nullable', 'email:rfc', 'max:254'],
            'contact_phone' => [
                'nullable',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => [
                'required',
                'string',
                'min:1',
                'max:'.(int) config('messaging.chat.max_message_length', 2000),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a phone number we can reach you on, for example +256700000000.',
        ];
    }
}
