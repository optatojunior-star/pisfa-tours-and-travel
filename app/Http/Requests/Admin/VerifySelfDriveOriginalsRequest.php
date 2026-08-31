<?php

namespace App\Http\Requests\Admin;

use App\Models\CarHireBooking;
use Illuminate\Foundation\Http\FormRequest;

class VerifySelfDriveOriginalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('carHireBooking');

        return $booking instanceof CarHireBooking
            && ($this->user()?->can('verifySelfDriveOriginals', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'originals_verified' => ['required', 'accepted'],
            'confirmation_phrase' => ['required', 'string', 'in:VERIFY ORIGINALS'],
        ];
    }

    public function messages(): array
    {
        return [
            'originals_verified.accepted' => 'Confirm that you personally checked the original documents.',
            'confirmation_phrase.in' => 'Type VERIFY ORIGINALS exactly to record this irreversible verification.',
        ];
    }
}
