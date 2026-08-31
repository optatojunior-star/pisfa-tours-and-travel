<?php

namespace App\Http\Requests\Admin;

use App\Support\Reports\ReportPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReportPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdministration() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the range cannot be before its start.',
        ];
    }

    /**
     * Bounds that ReportPeriod itself enforces — the maximum window in
     * particular — surface as validation errors rather than a 500.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                $this->period();
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('to', $exception->getMessage());
            }
        });
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::between(
            $this->input('from') === null ? null : (string) $this->input('from'),
            $this->input('to') === null ? null : (string) $this->input('to'),
        );
    }

    /** @throws ValidationException */
    public function validatedPeriod(): ReportPeriod
    {
        $this->validateResolved();

        return $this->period();
    }
}
