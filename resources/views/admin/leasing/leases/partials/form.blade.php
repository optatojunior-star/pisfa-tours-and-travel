@php
    /** @var \App\Models\VehicleLease|null $lease */
    $value = static fn (string $field, mixed $fallback = null) => old($field, $lease?->{$field} ?? $fallback);
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

@if ($application)
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
        Drawing up terms for the {{ $application->vehicleLabel() }}
        ({{ $application->registration_plate }}) offered by {{ $application->contact_name }}.
        Activating the agreement will add this vehicle to the fleet.
    </div>
    <input type="hidden" name="application_id" value="{{ $application->getKey() }}">
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h2 class="text-lg font-black text-slate-900">Terms</h2>

        <div>
            <label for="owner_id" class="block text-sm font-semibold">Owner</label>
            <select id="owner_id" name="owner_id" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <option value="">Choose the owner</option>
                @foreach ($owners as $person)
                    <option value="{{ $person->getKey() }}" @selected((int) old('owner_id', $lease?->owner_id) === (int) $person->getKey())>
                        {{ $person->name }} ({{ $person->email }})
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">
                Payouts are addressed to an account, so the owner needs an active customer account.
            </p>
        </div>

        <div>
            <label for="payout_model" class="block text-sm font-semibold">How the owner is paid</label>
            <select id="payout_model" name="payout_model" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                @foreach ($payoutModels as $model)
                    <option value="{{ $model->value }}" @selected(old('payout_model', $lease?->payout_model?->value) === $model->value)>{{ $model->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="monthly_retainer" class="block text-sm font-semibold">Monthly retainer</label>
                <input id="monthly_retainer" name="monthly_retainer" type="text" inputmode="numeric" maxlength="24"
                       value="{{ old('monthly_retainer', $lease?->monthly_retainer_minor !== null ? \App\Support\Money::forInput($lease->monthly_retainer_minor, $lease->currency) : '') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Only for a fixed monthly arrangement.</p>
            </div>
            <div>
                <label for="currency" class="block text-sm font-semibold">Currency</label>
                <select id="currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                        <option value="{{ $code }}" @selected(old('currency', $lease?->currency ?? config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label for="revenue_share_bps" class="block text-sm font-semibold">Revenue share (basis points)</label>
            <input id="revenue_share_bps" name="revenue_share_bps" type="number" min="1"
                   max="{{ (int) config('leasing.max_revenue_share_bps', 10000) }}"
                   value="{{ $value('revenue_share_bps') }}"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            <p class="mt-1 text-xs text-slate-500">
                2500 is 25%. Stored as an integer so a percentage is never a float, and the share is taken from
                hire income only — never from a refundable deposit.
            </p>
        </div>

        <div>
            <label for="terms" class="block text-sm font-semibold">Terms as written</label>
            <textarea id="terms" name="terms" rows="6" maxlength="8000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('terms') }}</textarea>
        </div>

        <div>
            <label for="internal_notes" class="block text-sm font-semibold">Internal notes</label>
            <textarea id="internal_notes" name="internal_notes" rows="3" maxlength="5000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $lease?->internal_notes) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Never shown to the owner.</p>
        </div>
    </section>

    <aside class="space-y-6 lg:col-span-1">
        <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">Dates</h2>

            <div>
                <label for="starts_on" class="block text-sm font-semibold">Starts</label>
                <input id="starts_on" name="starts_on" type="date" required
                       value="{{ old('starts_on', $lease?->starts_on?->toDateString()) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>

            <div>
                <label for="ends_on" class="block text-sm font-semibold">Ends</label>
                <input id="ends_on" name="ends_on" type="date"
                       value="{{ old('ends_on', $lease?->ends_on?->toDateString()) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Leave blank for an open-ended agreement.</p>
            </div>

            <div>
                <label for="notice_period_days" class="block text-sm font-semibold">Notice period (days)</label>
                <input id="notice_period_days" name="notice_period_days" type="number" required min="0" max="365"
                       value="{{ $value('notice_period_days', (int) config('leasing.default_notice_period_days', 30)) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </section>

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">What happens next</h2>
            <p class="mt-2 text-sm text-slate-600">
                Saving creates a draft. Terms can only be changed while it is a draft — once it is active the owner
                has been told what they will be paid, and statements have been computed against these numbers.
            </p>
            <p class="mt-2 text-sm text-slate-600">
                Activating the agreement adds the vehicle to the fleet.
            </p>
        </section>
    </aside>
</div>
