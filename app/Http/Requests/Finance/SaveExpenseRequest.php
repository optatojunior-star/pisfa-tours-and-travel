<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only.
 *
 * Whether a category can be charged to a vehicle, and whether the date is in
 * the future in Kampala rather than in UTC, belong to SaveExpense.
 */
class SaveExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expense = $this->route('expense');

        if ($expense instanceof Expense) {
            return $this->user()?->can('update', $expense) ?? false;
        }

        return $this->user()?->can('create', Expense::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'spent_on' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:180'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'description.required' => 'Say what the money was spent on — whoever approves this was not there.',
        ];
    }
}
