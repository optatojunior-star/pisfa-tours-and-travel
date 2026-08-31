@php
    use App\Enums\PaymentStatus;
    use App\Support\Payments\PayableRegistry;

    $retryUrl = $payment->payable === null ? null : PayableRegistry::checkoutUrl($payment->payable);

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $tone = match (true) {
        $payment->status->isSettled() => ['bg-emerald-50', 'text-emerald-900', 'border-emerald-200'],
        $payment->status->isInFlight() => ['bg-amber-50', 'text-amber-900', 'border-amber-200'],
        default => ['bg-rose-50', 'text-rose-900', 'border-rose-200'],
    };
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Payment</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $payment->reference }}</h1>
            </div>
            @if ($payment->payable !== null)
                <a href="{{ $payment->payable->paymentReturnUrl() }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to booking</a>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-3xl border {{ $tone[2] }} {{ $tone[0] }} p-6" role="status" aria-live="polite">
                <p class="text-sm font-bold uppercase tracking-wide {{ $tone[1] }}">{{ $payment->status->label() }}</p>
                <p class="mt-2 text-3xl font-black {{ $tone[1] }}">{{ $payment->formattedAmount() }}</p>
                @if ($payment->status === PaymentStatus::Paid)
                    <p class="mt-2 text-sm {{ $tone[1] }}">Received {{ $payment->paid_at?->timezone($timezone)->format('j M Y, H:i') }}. Thank you.</p>
                @elseif ($payment->status->isInFlight())
                    <p class="mt-2 text-sm {{ $tone[1] }}">We are waiting for confirmation. This page updates when the payment is confirmed — you do not need to pay again.</p>
                @elseif (filled($payment->failure_reason))
                    <p class="mt-2 text-sm {{ $tone[1] }}">{{ $payment->failure_reason }}</p>
                @endif
            </div>

            @if (! empty($instructions))
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="payment-instructions">
                    <h2 id="payment-instructions" class="text-lg font-black text-slate-950">{{ $instructions['headline'] ?? 'How to pay' }}</h2>
                    @if (filled($instructions['detail'] ?? null))
                        <p class="mt-2 text-sm text-slate-600">{{ $instructions['detail'] }}</p>
                    @endif
                    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach (['bank_name' => 'Bank', 'account_name' => 'Account name', 'account_number' => 'Account number', 'branch' => 'Branch', 'swift' => 'SWIFT', 'reference' => 'Payment reference', 'amount' => 'Exact amount'] as $key => $label)
                            @if (filled($instructions[$key] ?? null))
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                                    <dd class="mt-1 font-mono font-bold text-slate-900">{{ $instructions[$key] }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm font-semibold text-amber-900">Quote the payment reference exactly, or we cannot match your transfer to this booking.</p>
                </section>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="payment-detail">
                <h2 id="payment-detail" class="text-lg font-black text-slate-950">Payment detail</h2>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Method</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->provider->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->status->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->formattedAmount() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Started</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->created_at->timezone($timezone)->format('j M Y, H:i') }}</dd></div>
                    @if ($payment->refunded_amount_minor > 0)
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refunded</dt><dd class="mt-1 font-bold text-rose-800">{{ \App\Support\Money::format($payment->refunded_amount_minor, $payment->currency) }}</dd></div>
                        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net</dt><dd class="mt-1 font-bold text-slate-900">{{ $payment->formattedNetAmount() }}</dd></div>
                    @endif
                </dl>
            </section>

            @if ($retryUrl !== null && $payment->status->allowsRetry() && $payment->payable->acceptsPayment() && $payment->payable->outstandingAmountMinor() > 0)
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-black text-slate-950">Try again</h2>
                    <p class="mt-2 text-sm text-slate-600">This attempt did not complete, so nothing was charged. You can start a new payment.</p>
                    <a href="{{ $retryUrl }}" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Start a new payment</a>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
