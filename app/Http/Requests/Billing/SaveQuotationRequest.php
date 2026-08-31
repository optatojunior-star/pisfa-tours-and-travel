<?php

namespace App\Http\Requests\Billing;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quotation = $this->route('quotation');

        if ($quotation instanceof Quotation) {
            return $this->user()?->can('update', $quotation) ?? false;
        }

        return $this->user()?->can('create', Quotation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maximumItems = (int) config('billing.quotations.maximum_items', 40);
        $maximumQuantity = (int) config('billing.quotations.maximum_quantity', 10_000);

        return [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'discount' => ['nullable', 'string', 'max:24'],
            'deposit' => ['nullable', 'string', 'max:24'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:'.$maximumItems],
            'items.*.description' => ['required', 'string', 'min:2', 'max:255'],
            'items.*.unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$maximumQuantity],
            'items.*.unit_price' => ['required', 'string', 'max:24'],
        ];
    }

    /**
     * Drops blank rows the operator left behind rather than failing the whole
     * form on an empty spare line.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        $filtered = array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
                && (filled($item['description'] ?? null) || filled($item['unit_price'] ?? null)),
        ));

        $this->merge(['items' => $filtered]);
    }
}
