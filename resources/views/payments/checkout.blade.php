@php
    use App\Support\Money;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Checkout</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Pay for {{ $payable->paymentReference() }}</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This payment could not be started.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="checkout-summary">
                <h2 id="checkout-summary" class="text-lg font-black text-slate-950">What you are paying for</h2>
                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Service</dt>
                        <dd class="text-right font-semibold text-slate-900">{{ $payable->paymentDescription() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Reference</dt>
                        <dd class="font-mono text-right font-semibold text-emerald-700">{{ $payable->paymentReference() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-600">Full price</dt>
                        <dd class="text-right font-semibold text-slate-900">{{ Money::format($payable->payableAmountMinor(), $payable->payableCurrency()) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-t border-slate-200 pt-3">
                        <dt class="font-bold text-slate-900">Amount due now</dt>
                        <dd class="text-right text-xl font-black text-emerald-800">{{ Money::format($outstanding, $payable->payableCurrency()) }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-slate-500">This amount is calculated by PISFA from your booking. It cannot be changed from this page.</p>
            </section>

            @if (empty($providers))
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No payment method is available</h2>
                    <p class="mx-auto mt-2 max-w-lg text-sm text-slate-600">No configured payment method can process {{ $payable->payableCurrency() }} right now. Please <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">contact our team</a> quoting {{ $payable->paymentReference() }}.</p>
                </div>
            @else
                <form method="POST" action="{{ route('payments.checkout.store', [$type, $payable->paymentReference()]) }}" class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                    <fieldset class="space-y-3">
                        <legend class="text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Choose how to pay</legend>
                        @foreach ($providers as $index => $provider)
                            <label class="flex cursor-pointer items-start gap-4 rounded-2xl border border-slate-200 p-4 transition has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/40 has-[:checked]:ring-2 has-[:checked]:ring-emerald-600/20">
                                <input type="radio" name="provider" value="{{ $provider->value }}" required @checked(old('provider', $index === 0 ? $provider->value : null) === $provider->value) class="mt-1 size-5 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span>
                                    <span class="block font-bold text-slate-900">{{ $provider->label() }}</span>
                                    @if ($provider->isManual())
                                        <span class="mt-0.5 block text-sm text-slate-600">You will receive transfer instructions. We confirm receipt manually.</span>
                                    @else
                                        <span class="mt-0.5 block text-sm text-slate-600">You will be taken to {{ $provider->label() }} to complete payment securely.</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('provider')" class="mt-1" />
                    </fieldset>

                    <div class="flex items-start gap-3 rounded-xl bg-stone-100 p-4">
                        <input id="acknowledge-terms" name="acknowledge_terms" type="checkbox" value="1" required @checked(old('acknowledge_terms')) class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                        <label for="acknowledge-terms" class="text-sm text-slate-700">I confirm the amount above and accept the <a href="{{ route('terms') }}" class="font-bold text-emerald-800 underline">terms and conditions</a>.</label>
                    </div>
                    <x-input-error :messages="$errors->get('acknowledge_terms')" class="-mt-3" />

                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-6 py-3 text-sm font-black text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">Continue to payment</button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
