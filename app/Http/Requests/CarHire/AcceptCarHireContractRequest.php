<?php

namespace App\Http\Requests\CarHire;

use Illuminate\Foundation\Http\FormRequest;

class AcceptCarHireContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->booking();

        return $booking !== null && ($this->user()?->can('acceptContract', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accept_contract' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_contract.accepted' => 'Confirm that you accept the issued car-hire contract.',
        ];
    }

    private function booking(): mixed
    {
        return $this->route('customerCarHireBooking')
            ?? $this->route('customerHireBooking')
            ?? $this->route('carHireBooking')
            ?? $this->route('hireBooking');
    }
}
