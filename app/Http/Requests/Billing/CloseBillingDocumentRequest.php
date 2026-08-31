<?php

namespace App\Http\Requests\Billing;

use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A cancellation or void always carries a reason: these are the entries an
 * auditor asks about, and "cancelled" with no explanation answers nothing.
 */
class CloseBillingDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $quotation = $this->route('quotation');

        if ($quotation instanceof Quotation) {
            return $user->can('cancel', $quotation);
        }

        $invoice = $this->route('invoice');

        if (! $invoice instanceof Invoice) {
            return false;
        }

        return $this->routeIs('admin.invoices.void')
            ? $user->can('void', $invoice)
            : $user->can('cancel', $invoice);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason. It is recorded against the document permanently.',
        ];
    }
}
