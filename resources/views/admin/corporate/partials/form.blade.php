@php
    /** @var \App\Models\CorporateAccount|null $account */
    $value = static fn (string $field, mixed $fallback = null) => old($field, $account?->{$field} ?? $fallback);
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
        <p class="font-bold">Please check the form.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h2 class="text-lg font-black text-slate-900">The company</h2>

        <div>
            <label for="name" class="block text-sm font-semibold">Registered name</label>
            <input id="name" name="name" type="text" required minlength="2" maxlength="200"
                   value="{{ $value('name') }}"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            @if ($account?->activated_at)
                <p class="mt-1 text-xs text-slate-500">
                    The console address is frozen at <span class="font-mono">{{ $account->slug }}</span> now the
                    account has traded.
                </p>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="registration_number" class="block text-sm font-semibold">Registration no.</label>
                <input id="registration_number" name="registration_number" type="text" maxlength="60"
                       value="{{ $value('registration_number') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="tax_identification_number" class="block text-sm font-semibold">TIN</label>
                <input id="tax_identification_number" name="tax_identification_number" type="text" maxlength="40"
                       value="{{ $value('tax_identification_number') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="industry" class="block text-sm font-semibold">Sector</label>
                <input id="industry" name="industry" type="text" maxlength="120" value="{{ $value('industry') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>

        <h2 class="pt-2 text-lg font-black text-slate-900">Who we bill</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="billing_contact_name" class="block text-sm font-semibold">Contact name</label>
                <input id="billing_contact_name" name="billing_contact_name" type="text" required maxlength="180"
                       value="{{ $value('billing_contact_name') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="billing_contact_email" class="block text-sm font-semibold">Email</label>
                <input id="billing_contact_email" name="billing_contact_email" type="email" required maxlength="254"
                       value="{{ $value('billing_contact_email') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="billing_contact_phone" class="block text-sm font-semibold">Phone</label>
                <input id="billing_contact_phone" name="billing_contact_phone" type="tel" required maxlength="40"
                       value="{{ $value('billing_contact_phone') }}" placeholder="+256414000000"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="billing_address" class="block text-sm font-semibold">Address</label>
                <input id="billing_address" name="billing_address" type="text" maxlength="500"
                       value="{{ $value('billing_address') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>

        <div>
            <label for="notes" class="block text-sm font-semibold">Notes for the company</label>
            <textarea id="notes" name="notes" rows="3" maxlength="2000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('notes') }}</textarea>
        </div>

        <div>
            <label for="internal_notes" class="block text-sm font-semibold">Internal notes</label>
            <textarea id="internal_notes" name="internal_notes" rows="3" maxlength="5000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $account?->internal_notes) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Never shown to the company.</p>
        </div>
    </section>

    <aside class="space-y-6 lg:col-span-1">
        @if ($account === null)
            <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Terms</h2>
                <p class="text-sm text-slate-600">
                    What the business is willing to be owed. Changed afterwards through its own manager-only form.
                </p>

                <div>
                    <label for="payment_terms_days" class="block text-sm font-semibold">Payment terms (days)</label>
                    <input id="payment_terms_days" name="payment_terms_days" type="number" required min="0" max="180"
                           value="{{ old('payment_terms_days', (int) config('corporate.default_payment_terms_days', 30)) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <p class="mt-1 text-xs text-slate-500">An invoice falls due this many days after it is issued.</p>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label for="credit_limit" class="block text-sm font-semibold">Credit limit</label>
                        <input id="credit_limit" name="credit_limit" type="text" inputmode="numeric" required maxlength="24"
                               value="{{ old('credit_limit', '0') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-semibold">Currency</label>
                        <select id="currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                <option value="{{ $code }}" @selected(old('currency', config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label for="discount_bps" class="block text-sm font-semibold">Discount (basis points)</label>
                    <input id="discount_bps" name="discount_bps" type="number" min="0"
                           max="{{ (int) config('corporate.max_discount_bps', 9900) }}"
                           value="{{ old('discount_bps', 0) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <p class="mt-1 text-xs text-slate-500">
                        500 is 5%. Stored as an integer so a percentage is never a float.
                    </p>
                </div>
            </section>
        @else
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Terms</h2>
                <p class="mt-2 text-sm text-slate-600">{{ $account->termsSummary() }}</p>
                <p class="mt-2 text-xs text-slate-500">
                    Changed from the account page, by a manager. The balance is never stored — it is worked out from
                    the invoices actually out.
                </p>
            </section>
        @endif
    </aside>
</div>
