@php
    use App\Support\Money;

    $isEdit = $quotation !== null;
    $currencies = config('pisfa.currency.supported', ['UGX', 'USD']);
    $currency = old('currency', $isEdit ? $quotation->currency : 'UGX');
    $maximumItems = (int) config('billing.quotations.maximum_items', 40);

    // Existing lines, else whatever the failed submit had, else three blanks.
    $existing = $isEdit
        ? $quotation->items->map(fn ($item) => [
            'description' => $item->description,
            'unit_label' => $item->unit_label,
            'quantity' => $item->quantity,
            'unit_price' => Money::forInput($item->unit_price_minor, $quotation->currency),
        ])->all()
        : [];
    $rows = old('items', $existing !== [] ? $existing : [
        ['description' => '', 'unit_label' => '', 'quantity' => 1, 'unit_price' => ''],
        ['description' => '', 'unit_label' => '', 'quantity' => 1, 'unit_price' => ''],
        ['description' => '', 'unit_label' => '', 'quantity' => 1, 'unit_price' => ''],
    ]);
@endphp

<form method="POST"
      action="{{ $isEdit ? route('admin.quotations.update', $quotation) : route('admin.quotations.store') }}"
      class="space-y-6">
    @csrf
    @if ($isEdit) @method('PATCH') @endif
    @if (! $isEdit && $source)
        <input type="hidden" name="quotation_request_reference" value="{{ $source->reference }}">
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <p class="font-bold">Check the quotation.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-subject">
        <h2 id="qtn-subject" class="text-lg font-black text-slate-950">Subject and recipient</h2>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="title" class="block text-sm font-semibold text-slate-800">Title</label>
                <input id="title" name="title" type="text" required minlength="3" maxlength="180"
                       value="{{ old('title', $isEdit ? $quotation->title : ($source?->serviceLabel() ?? '')) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('title')" class="mt-1" />
            </div>

            <div>
                <label for="contact_name" class="block text-sm font-semibold text-slate-800">Contact name</label>
                <input id="contact_name" name="contact_name" type="text" required minlength="2" maxlength="180"
                       value="{{ old('contact_name', $isEdit ? $quotation->contact_name : $source?->contact_name) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
            </div>
            <div>
                <label for="company_name" class="block text-sm font-semibold text-slate-800">Organisation</label>
                <input id="company_name" name="company_name" type="text" maxlength="180"
                       value="{{ old('company_name', $isEdit ? $quotation->company_name : $source?->company_name) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('company_name')" class="mt-1" />
            </div>
            <div>
                <label for="contact_email" class="block text-sm font-semibold text-slate-800">Email</label>
                <input id="contact_email" name="contact_email" type="email" required maxlength="254"
                       value="{{ old('contact_email', $isEdit ? $quotation->contact_email : $source?->contact_email) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_email')" class="mt-1" />
            </div>
            <div>
                <label for="contact_phone" class="block text-sm font-semibold text-slate-800">Phone</label>
                <input id="contact_phone" name="contact_phone" type="tel" required maxlength="40"
                       value="{{ old('contact_phone', $isEdit ? $quotation->contact_phone : $source?->contact_phone) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_phone')" class="mt-1" />
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-items">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="qtn-items" class="text-lg font-black text-slate-950">Priced items</h2>
            <p class="text-xs text-slate-500">Up to {{ $maximumItems }} lines. Blank rows are ignored.</p>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="min-w-full text-sm">
                <caption class="sr-only">Quotation line items</caption>
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="pb-2 pr-3">Description</th>
                        <th scope="col" class="pb-2 pr-3 w-24">Qty</th>
                        <th scope="col" class="pb-2 pr-3 w-28">Unit</th>
                        <th scope="col" class="pb-2 w-40">Unit price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $index => $row)
                        <tr>
                            <td class="py-1 pr-3">
                                <label class="sr-only" for="item-{{ $index }}-description">Line {{ $index + 1 }} description</label>
                                <input id="item-{{ $index }}-description" name="items[{{ $index }}][description]" type="text" maxlength="255"
                                       value="{{ $row['description'] ?? '' }}"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </td>
                            <td class="py-1 pr-3">
                                <label class="sr-only" for="item-{{ $index }}-quantity">Line {{ $index + 1 }} quantity</label>
                                <input id="item-{{ $index }}-quantity" name="items[{{ $index }}][quantity]" type="number" min="1" inputmode="numeric"
                                       value="{{ $row['quantity'] ?? 1 }}"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </td>
                            <td class="py-1 pr-3">
                                <label class="sr-only" for="item-{{ $index }}-unit">Line {{ $index + 1 }} unit label</label>
                                <input id="item-{{ $index }}-unit" name="items[{{ $index }}][unit_label]" type="text" maxlength="24"
                                       placeholder="days" value="{{ $row['unit_label'] ?? '' }}"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </td>
                            <td class="py-1">
                                <label class="sr-only" for="item-{{ $index }}-price">Line {{ $index + 1 }} unit price</label>
                                <input id="item-{{ $index }}-price" name="items[{{ $index }}][unit_price]" type="text" inputmode="decimal" maxlength="24"
                                       value="{{ $row['unit_price'] ?? '' }}"
                                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-input-error :messages="$errors->get('items')" class="mt-2" />
        <p class="mt-3 text-xs text-slate-500">
            Enter prices in whole {{ $currency }} for UGX, or with two decimals for USD. No thousands separators.
        </p>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="qtn-terms">
        <h2 id="qtn-terms" class="text-lg font-black text-slate-950">Pricing and terms</h2>

        <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                <select id="currency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($currencies as $option)
                        <option value="{{ $option }}" @selected($currency === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('currency')" class="mt-1" />
            </div>
            <div>
                <label for="discount" class="block text-sm font-semibold text-slate-800">Discount</label>
                <input id="discount" name="discount" type="text" inputmode="decimal" maxlength="24"
                       value="{{ old('discount', $isEdit && $quotation->discount_minor > 0 ? Money::forInput($quotation->discount_minor, $quotation->currency) : '') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('discount')" class="mt-1" />
            </div>
            <div>
                <label for="tax_rate_bps" class="block text-sm font-semibold text-slate-800">Tax rate (basis points)</label>
                <input id="tax_rate_bps" name="tax_rate_bps" type="number" min="0" max="10000" step="1"
                       value="{{ old('tax_rate_bps', $isEdit ? $quotation->tax_rate_bps : config('billing.tax.default_rate_bps', 1800)) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">1800 = 18% {{ config('billing.tax.label', 'VAT') }}. Use 0 for no tax.</p>
                <x-input-error :messages="$errors->get('tax_rate_bps')" class="mt-1" />
            </div>
            <div>
                <label for="deposit" class="block text-sm font-semibold text-slate-800">Deposit</label>
                <input id="deposit" name="deposit" type="text" inputmode="decimal" maxlength="24"
                       value="{{ old('deposit', $isEdit && $quotation->deposit_minor ? Money::forInput($quotation->deposit_minor, $quotation->currency) : '') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Optional. Leave blank to collect the total in one payment.</p>
                <x-input-error :messages="$errors->get('deposit')" class="mt-1" />
            </div>
            <div>
                <label for="valid_until" class="block text-sm font-semibold text-slate-800">Valid until</label>
                <input id="valid_until" name="valid_until" type="date" min="{{ now()->toDateString() }}"
                       value="{{ old('valid_until', $isEdit ? $quotation->valid_until?->toDateString() : now()->addDays((int) config('billing.quotations.default_validity_days', 14))->toDateString()) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('valid_until')" class="mt-1" />
            </div>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-2">
            <div>
                <label for="notes" class="block text-sm font-semibold text-slate-800">Notes to the customer</label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000"
                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes', $isEdit ? $quotation->notes : '') }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>
            <div>
                <label for="terms" class="block text-sm font-semibold text-slate-800">Terms</label>
                <textarea id="terms" name="terms" rows="4" maxlength="5000"
                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('terms', $isEdit ? $quotation->terms : '') }}</textarea>
                <x-input-error :messages="$errors->get('terms')" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
                <label for="internal_notes" class="block text-sm font-semibold text-slate-800">Internal notes</label>
                <textarea id="internal_notes" name="internal_notes" rows="3" maxlength="5000"
                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $isEdit ? $quotation->internal_notes : '') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Never shown to the customer and never rendered on the PDF.</p>
                <x-input-error :messages="$errors->get('internal_notes')" class="mt-1" />
            </div>
        </div>
    </section>

    <div class="flex flex-wrap gap-3">
        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-800">
            {{ $isEdit ? 'Save the draft' : 'Create the draft' }}
        </button>
        <a href="{{ $isEdit ? route('admin.quotations.show', $quotation) : route('admin.quotations.index') }}"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-6 py-3 text-sm font-bold text-slate-700">
            Cancel
        </a>
    </div>
</form>
