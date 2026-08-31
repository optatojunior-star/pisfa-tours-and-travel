<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class PostChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Entitlement to this particular conversation is resolved in the
        // controller, from the session rather than the reference in the URL.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => [
                'required',
                'string',
                'min:1',
                'max:'.(int) config('messaging.chat.max_message_length', 2000),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
