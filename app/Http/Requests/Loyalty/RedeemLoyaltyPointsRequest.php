<?php

namespace App\Http\Requests\Loyalty;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class RedeemLoyaltyPointsRequest extends FormRequest
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
            // The minimum and the balance ceiling are enforced server-side in
            // the action under a row lock; this only rejects nonsense early.
            'points' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'confirm_redemption' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm_redemption.accepted' => 'Please confirm you want to redeem these points.',
        ];
    }
}
