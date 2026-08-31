@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $refundable = $payment->refundableAmountMinor();
    $canRefund = auth()->user()->can('refund', $payment) && $refundable > 0;
    $canRecord = $payment->provider->isManual()
        && $payment->status->isInFlight()
        && auth()->user()->can('record', \App\Models\Payment::class);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Transaction</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $payment->reference }}</h1>
            </div>
            <a href="{{ route('admin.payments.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to transactions</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This operation was rejected.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="txn-summary">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="txn-summary" class="text-lg font-black text-slate-950">{{ $payment->formattedAmount() }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $payment->status->label() }}</span>
                </div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Method</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->provider->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->customer?->name ?? 'Guest' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->customer?->email ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">For</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->payable?->paymentDescription() ?? 'Unallocated' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">In base currency</dt><dd class="mt-1 font-bold text-slate-900">{{ Money::format($payment->base_amount_minor, $payment->base_currency) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rate applied</dt><dd class="mt-1 font-bold text-slate-900">{{ number_format($payment->exchange_rate_ppm / 1000000, 6) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Started</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->created_at->timezone($timezone)->format('j M Y, H:i') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Settled</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->paid_at?->timezone($timezone)->format('j M Y, H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refundable now</dt><dd class="mt-1 font-bold text-slate-900">{{ Money::format($refundable, $payment->currency) }}</dd></div>
                    @if (filled($payment->failure_reason))
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Failure reason</dt><dd class="mt-1 text-rose-800">{{ $payment->failure_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            @if ($canRecord)
                <section class="rounded-3xl border border-amber-200 bg-amber-50/50 p-6 shadow-sm" aria-labelledby="record-receipt">
                    <h2 id="record-receipt" class="text-lg font-black text-slate-950">Record receipt</h2>
                    <p class="mt-2 text-sm text-slate-700">Only record this once the funds are actually in the PISFA account. This settles the payment and credits the booking.</p>
                    <form method="POST" action="{{ route('admin.payments.record', $payment) }}" class="mt-4 space-y-4">
                        @csrf
                        <div>
                            <label for="evidence" class="block text-sm font-semibold text-slate-800">Bank slip or receipt reference</label>
                            <input id="evidence" name="evidence_reference" type="text" required minlength="3" maxlength="120" value="{{ old('evidence_reference') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('evidence_reference')" class="mt-1" />
                        </div>
                        <div class="flex items-start gap-3">
                            <input id="confirm-received" name="confirm_received" type="checkbox" value="1" required class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            <label for="confirm-received" class="text-sm text-slate-700">I confirm {{ $payment->formattedAmount() }} has been received.</label>
                        </div>
                        <x-input-error :messages="$errors->get('confirm_received')" class="mt-1" />
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Record receipt</button>
                    </form>
                </section>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="txn-allocations">
                <h2 id="txn-allocations" class="text-lg font-black text-slate-950">Allocation</h2>
                @if ($payment->allocations->isEmpty())
                    <p class="mt-2 text-sm {{ $payment->isSettled() ? 'font-semibold text-amber-800' : 'text-slate-600' }}">
                        {{ $payment->isSettled()
                            ? 'This payment is settled but has not been credited to any service. It needs reconciliation.'
                            : 'Nothing is allocated until the payment settles.' }}
                    </p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($payment->allocations as $allocation)
                            <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                <p class="font-semibold text-slate-900">{{ $allocation->formattedAmount() }} &rarr; {{ class_basename($allocation->allocatable_type) }} #{{ $allocation->allocatable_id }}</p>
                                <p class="mt-1 text-xs text-slate-600">{{ $allocation->created_at->timezone($timezone)->format('j M Y, H:i') }} · {{ $allocation->allocatedBy?->name ?? 'system' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="txn-refunds">
                <h2 id="txn-refunds" class="text-lg font-black text-slate-950">Refunds</h2>

                @if ($payment->refunds->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">No refunds have been requested against this payment.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($payment->refunds as $refund)
                            <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-semibold text-slate-900">{{ $refund->formattedAmount() }}</p>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $refund->status->label() }}</span>
                                </div>
                                <p class="mt-2 text-slate-700">{{ $refund->reason }}</p>
                                <p class="mt-2 text-xs text-slate-600">{{ $refund->created_at->timezone($timezone)->format('j M Y, H:i') }} · {{ $refund->requestedBy?->name ?? 'system' }}</p>
                                @if (filled($refund->failure_reason))
                                    <p class="mt-1 text-xs font-semibold text-rose-700">{{ $refund->failure_reason }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canRefund)
                    <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', Str::uuid()) }}">
                        <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Issue a refund</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="refund-amount" class="block text-sm font-semibold text-slate-800">Amount ({{ $payment->currency }})</label>
                                <input id="refund-amount" name="amount" type="text" inputmode="decimal" required maxlength="24" value="{{ old('amount', Money::forInput($refundable, $payment->currency)) }}" class="mt-1 block w-full rounded-xl border-slate-300">
                                <p class="mt-1 text-xs text-slate-500">Up to {{ Money::format($refundable, $payment->currency) }}.</p>
                                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
                            </div>
                            <div>
                                <label for="refund-reason" class="block text-sm font-semibold text-slate-800">Reason</label>
                                <input id="refund-reason" name="reason" type="text" required minlength="5" maxlength="1000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                                <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <input id="confirm-refund" name="confirm_refund" type="checkbox" value="1" required class="mt-0.5 size-5 rounded border-slate-400 text-rose-700 focus:ring-rose-600">
                            <label for="confirm-refund" class="text-sm text-slate-700">I confirm this refund should be sent to the customer.</label>
                        </div>
                        <x-input-error :messages="$errors->get('confirm_refund')" class="mt-1" />
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-rose-700 px-5 py-3 text-sm font-bold text-white hover:bg-rose-800">Issue refund</button>
                    </form>
                @elseif ($refundable > 0)
                    <p class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-700" role="note">Refunds are restricted to managers and super administrators.</p>
                @endif
            </section>

            @if ($payment->webhookEvents->isNotEmpty())
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="txn-events">
                    <h2 id="txn-events" class="text-lg font-black text-slate-950">Provider notifications</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($payment->webhookEvents as $event)
                            <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                <p class="font-semibold text-slate-900">{{ $event->event_type ?? 'notification' }}</p>
                                <p class="mt-1 text-xs text-slate-600">
                                    Received {{ $event->received_at->timezone($timezone)->format('j M Y, H:i') }} ·
                                    {{ $event->isProcessed() ? 'processed' : 'not processed' }}
                                </p>
                                @if (filled($event->processing_error))
                                    <p class="mt-1 text-xs font-semibold text-rose-700">{{ $event->processing_error }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
