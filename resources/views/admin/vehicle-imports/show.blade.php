@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currencies = config('pisfa.currency.supported', ['UGX', 'USD']);
    $transitions = $order->status->allowedTransitions();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Vehicle import</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $order->reference }}</h1>
            </div>
            <a href="{{ route('admin.vehicle-imports.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to imports</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This operation was rejected.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @include('vehicle-imports.partials.progress', ['order' => $order])

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-requester">
                <h2 id="import-requester" class="text-lg font-black text-slate-950">Requester</h2>
                <dl class="mt-4 grid gap-5 sm:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->contact_name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->contact_email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phone</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->contact_phone }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->isGuest() ? 'Guest request' : $order->customer?->name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Consultant</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->assignee?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Settled so far</dt><dd class="mt-1 font-bold text-emerald-800">{{ Money::format($order->settledAmountMinor(), $order->payableCurrency()) }}</dd></div>
                    @if (filled($order->notes))
                        <div class="sm:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer notes</dt><dd class="mt-1 text-slate-800">{{ $order->notes }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-quote">
                <h2 id="import-quote" class="text-lg font-black text-slate-950">Quotation</h2>
                @if ($order->depositIsSettled())
                    <p class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-700" role="note">
                        The deposit has been paid against this quotation, so the price is fixed. Raise a new
                        import request if the customer needs different terms.
                    </p>
                @else
                    <p class="mt-2 text-sm text-slate-600">Publishing a quotation notifies the customer and opens the deposit for payment. Re-quoting replaces the current figures until the deposit is paid.</p>
                    <form method="POST" action="{{ route('admin.vehicle-imports.quote', $order) }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        <div>
                            <label for="total-price" class="block text-sm font-semibold text-slate-800">Total price</label>
                            <input id="total-price" name="total_price" type="text" inputmode="decimal" required maxlength="24" value="{{ old('total_price', $order->total_price_minor !== null ? Money::forInput((int) $order->total_price_minor, (string) $order->quote_currency) : '') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('total_price')" class="mt-1" />
                        </div>
                        <div>
                            <label for="deposit" class="block text-sm font-semibold text-slate-800">Deposit</label>
                            <input id="deposit" name="deposit" type="text" inputmode="decimal" required maxlength="24" value="{{ old('deposit', $order->deposit_minor !== null ? Money::forInput((int) $order->deposit_minor, (string) $order->quote_currency) : '') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('deposit')" class="mt-1" />
                        </div>
                        <div>
                            <label for="quote-currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                            <select id="quote-currency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($currencies as $currency)
                                    <option value="{{ $currency }}" @selected(old('currency', $order->quote_currency ?? $order->budget_currency) === $currency)>{{ $currency }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="arrival" class="block text-sm font-semibold text-slate-800">Estimated arrival</label>
                            <input id="arrival" name="estimated_arrival_on" type="date" required value="{{ old('estimated_arrival_on', $order->estimated_arrival_on?->toDateString()) }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('estimated_arrival_on')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Publish quotation</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-status">
                <h2 id="import-status" class="text-lg font-black text-slate-950">Status</h2>
                @if ($transitions === [])
                    <p class="mt-2 text-sm text-slate-600">{{ $order->status->label() }} is a terminal status.</p>
                @else
                    <form method="POST" action="{{ route('admin.vehicle-imports.transition', $order) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="next-status" class="block text-sm font-semibold text-slate-800">Move to</label>
                            <select id="next-status" name="status" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($transitions as $next)
                                    <option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('status')" class="mt-1" />
                        </div>
                        <div>
                            <label for="status-reason" class="block text-sm font-semibold text-slate-800">Reason <span class="font-normal text-slate-500">(required to cancel)</span></label>
                            <input id="status-reason" name="reason" type="text" minlength="5" maxlength="1000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2 flex items-start gap-3">
                            <input id="notify-customer" name="notify_customer" type="checkbox" value="1" checked class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            <label for="notify-customer" class="text-sm text-slate-700">Email the customer about this change.</label>
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Apply status change</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-history">
                <h2 id="import-history" class="text-lg font-black text-slate-950">Full history</h2>
                @if ($order->events->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">Nothing recorded yet.</p>
                @else
                    <ol class="mt-4 space-y-3">
                        @foreach ($order->events as $event)
                            <li @class([
                                'rounded-2xl border p-4 text-sm',
                                'border-slate-200' => $event->is_customer_visible,
                                'border-amber-200 bg-amber-50/40' => ! $event->is_customer_visible,
                            ])>
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $event->event_type->label() }}@unless ($event->is_customer_visible) · internal @endunless</p>
                                <p class="mt-1 text-slate-800">{{ $event->summary }}</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $event->created_at->timezone($timezone)->format('j M Y, H:i') }} · {{ $event->actor?->name ?? 'system' }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
