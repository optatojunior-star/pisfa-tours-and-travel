@php
    use App\Enums\QuotationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    // Server-derived: the customer can only act when the offer is genuinely
    // open, so no button is rendered that the action would then refuse.
    $canRespond = $quotation->awaitsResponse();
    $respondAction = $isGuestView
        ? route('quotations.track.respond', ['token' => $quotation->tracking_token])
        : route('portal.quotations.respond', $quotation);
@endphp

<article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Quotation</p>
            <h1 class="mt-1 font-mono text-2xl font-black text-slate-950">{{ $quotation->number }}</h1>
            <p class="mt-2 text-lg font-bold text-slate-900">{{ $quotation->title }}</p>
            @if ($quotation->revision > 1)
                <p class="mt-1 text-xs font-semibold text-slate-500">Revision {{ $quotation->revision }} — this replaces any earlier version.</p>
            @endif
        </div>
        <x-billing-status :status="$quotation->status" />
    </div>

    <dl class="mt-6 grid gap-4 border-y border-slate-200 py-5 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Issued</dt>
            <dd class="mt-1 text-slate-800">{{ $quotation->sent_at?->timezone($timezone)->format('j M Y') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Valid until</dt>
            <dd class="mt-1 text-slate-800">
                {{ $quotation->valid_until?->format('j M Y') ?? 'No expiry' }}
                @if ($quotation->hasExpired())
                    <span class="ml-1 font-bold text-rose-700">(passed)</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total</dt>
            <dd class="mt-1 text-lg font-black text-emerald-800">{{ $quotation->formattedTotal() }}</dd>
        </div>
    </dl>

    <div class="mt-6">
        <h2 class="text-sm font-black uppercase tracking-wide text-slate-700">Priced items</h2>
        <div class="mt-3">
            <x-billing-lines :document="$quotation" />
        </div>
    </div>

    @if ($quotation->notes)
        <section class="mt-6" aria-labelledby="quotation-notes">
            <h2 id="quotation-notes" class="text-sm font-black uppercase tracking-wide text-slate-700">Notes</h2>
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $quotation->notes }}</p>
        </section>
    @endif

    @if ($quotation->terms)
        <section class="mt-6" aria-labelledby="quotation-terms">
            <h2 id="quotation-terms" class="text-sm font-black uppercase tracking-wide text-slate-700">Terms</h2>
            <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $quotation->terms }}</p>
        </section>
    @endif
</article>

@if ($canRespond)
    <section class="rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6 shadow-sm" aria-labelledby="quotation-decision">
        <h2 id="quotation-decision" class="text-lg font-black text-slate-950">Your decision</h2>
        <p class="mt-2 text-sm text-slate-700">
            Accepting produces an invoice for {{ $quotation->formattedTotal() }}. Nothing is charged until you
            pay that invoice.
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <form method="POST" action="{{ $respondAction }}">
                @csrf
                <input type="hidden" name="decision" value="accept">
                <button type="submit"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">
                    Accept this quotation
                </button>
            </form>

            <form method="POST" action="{{ $respondAction }}" class="space-y-2">
                @csrf
                <input type="hidden" name="decision" value="decline">
                <label for="decline-reason" class="block text-sm font-semibold text-slate-800">If declining, tell us why</label>
                <input id="decline-reason" name="reason" type="text" minlength="3" maxlength="500"
                       value="{{ old('reason') }}"
                       class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('reason')" />
                <button type="submit"
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 bg-white px-5 py-3 text-sm font-bold text-rose-700 hover:bg-rose-50">
                    Decline
                </button>
            </form>
        </div>
    </section>
@elseif ($quotation->status === QuotationStatus::Accepted)
    <section class="rounded-3xl border border-emerald-200 bg-emerald-50/60 p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">You accepted this quotation</h2>
        @if ($quotation->invoice && $quotation->invoice->status->isVisibleToCustomer())
            <p class="mt-2 text-sm text-slate-700">Invoice {{ $quotation->invoice->number }} has been raised.</p>
            <a href="{{ $quotation->invoice->viewUrl() }}"
               class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                View the invoice
            </a>
        @else
            <p class="mt-2 text-sm text-slate-700">We are preparing your invoice and will email it shortly.</p>
        @endif
    </section>
@elseif ($quotation->status === QuotationStatus::Declined)
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">You declined this quotation</h2>
        @if ($quotation->decline_reason)
            <p class="mt-2 text-sm text-slate-700">Reason recorded: {{ $quotation->decline_reason }}</p>
        @endif
        <p class="mt-2 text-sm text-slate-600">If circumstances change, ask us for a fresh quotation.</p>
    </section>
@elseif ($quotation->hasExpired() || $quotation->status === QuotationStatus::Expired)
    <section class="rounded-3xl border border-amber-200 bg-amber-50 p-6 shadow-sm">
        <h2 class="text-lg font-black text-slate-950">This offer has expired</h2>
        <p class="mt-2 text-sm text-slate-700">
            The prices above are no longer held. Ask us for an up-to-date quotation and we will re-price it.
        </p>
        <a href="{{ route('request-quotation') }}"
           class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
            Request a fresh quotation
        </a>
    </section>
@endif
