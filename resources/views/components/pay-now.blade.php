@props(['payable'])

@php
    use App\Support\Money;
    use App\Support\Payments\PayableRegistry;

    $outstanding = $payable->outstandingAmountMinor();
    $checkoutUrl = PayableRegistry::checkoutUrl($payable);
    $payable->loadMissing('payments');
    $inFlight = $payable->payments->first(fn ($payment) => $payment->status->isInFlight());
@endphp

<section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="pay-now-heading">
    <h2 id="pay-now-heading" class="text-lg font-black text-slate-950">Payment</h2>

    @if ($outstanding === 0 && $payable->payableAmountMinor() > 0)
        <p class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">
            Paid in full — {{ Money::format($payable->payableAmountMinor(), $payable->payableCurrency()) }}. Thank you.
        </p>
    @elseif ($inFlight !== null)
        <p class="mt-3 text-sm text-slate-600">A payment of {{ $inFlight->formattedAmount() }} is already in progress. You do not need to start another.</p>
        <a href="{{ route('payments.status', $inFlight) }}" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-5 py-3 text-sm font-bold text-emerald-800 hover:bg-emerald-50">View payment status</a>
    @elseif (! $payable->acceptsPayment())
        {{-- Wording is deliberately neutral: the component now serves bookings,
             imports, and invoices alike. --}}
        <p class="mt-3 text-sm text-slate-600">This is not currently accepting payment. <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Contact our team</a> if you believe that is wrong.</p>
    @elseif ($checkoutUrl === null)
        <p class="mt-3 text-sm text-slate-600">Online payment is not available for this service yet. Our team will be in touch with payment instructions.</p>
    @else
        <dl class="mt-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount due</dt>
                <dd class="mt-1 text-2xl font-black text-emerald-800">{{ Money::format($outstanding, $payable->payableCurrency()) }}</dd>
            </div>
        </dl>
        <a href="{{ $checkoutUrl }}" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-6 py-3 text-sm font-black text-emerald-950 transition hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 sm:w-auto">Pay now</a>
    @endif
</section>
