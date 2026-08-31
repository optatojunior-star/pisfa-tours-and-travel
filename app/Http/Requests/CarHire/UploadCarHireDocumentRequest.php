<?php

namespace App\Http\Requests\CarHire;

use App\Enums\CarHireDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadCarHireDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->booking();

        return $booking !== null && ($this->user()?->can('uploadDocument', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(CarHireDocumentType::class)],
            'file' => [
                'required',
                'file',
                'max:'.config('car_hire.documents.maximum_kilobytes', 5120),
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'extensions:pdf,jpg,jpeg,png',
            ],
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
