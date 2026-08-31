@php
    use App\Enums\QuotationRequestStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp

<article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Quotation request</p>
            <h1 class="mt-1 font-mono text-2xl font-black text-slate-950">{{ $request->reference }}</h1>
            <p class="mt-2 text-lg font-bold text-slate-900">{{ $request->serviceLabel() }}</p>
        </div>
        <span @class([
            'rounded-full px-3 py-1 text-xs font-bold',
            'bg-sky-50 text-sky-800' => $request->status === QuotationRequestStatus::New,
            'bg-amber-50 text-amber-900' => $request->status === QuotationRequestStatus::InReview,
            'bg-emerald-50 text-emerald-800' => $request->status === QuotationRequestStatus::Quoted,
            'bg-slate-100 text-slate-700' => $request->status === QuotationRequestStatus::Closed,
            'bg-rose-50 text-rose-800' => $request->status === QuotationRequestStatus::Cancelled,
        ])>{{ $request->status->label() }}</span>
    </div>

    <dl class="mt-6 grid gap-4 border-y border-slate-200 py-5 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Requested</dt>
            <dd class="mt-1 text-slate-800">{{ $request->created_at->timezone($timezone)->format('j M Y') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Preferred date</dt>
            <dd class="mt-1 text-slate-800">{{ $request->preferred_date?->format('j M Y') ?? 'Flexible' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Budget given</dt>
            <dd class="mt-1 text-slate-800">{{ $request->formattedBudget() ?? 'Not stated' }}</dd>
        </div>
    </dl>

    <section class="mt-6" aria-labelledby="request-details">
        <h2 id="request-details" class="text-sm font-black uppercase tracking-wide text-slate-700">What you asked for</h2>
        <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $request->details }}</p>
        @if ($request->party_size)
            <p class="mt-3 text-sm text-slate-600">Party size: <span class="font-semibold">{{ $request->party_size }}</span></p>
        @endif
    </section>
</article>

<section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="request-quotations">
    <h2 id="request-quotations" class="text-lg font-black text-slate-950">Quotations</h2>

    @if ($quotations->isEmpty())
        <p class="mt-3 text-sm text-slate-600">
            @if ($request->status === QuotationRequestStatus::Cancelled)
                This request was closed without a quotation.
            @else
                We are pricing this. A written quotation will be emailed to
                <span class="font-semibold">{{ $request->contact_email }}</span>.
            @endif
        </p>
    @else
        <ul class="mt-4 space-y-3">
            @foreach ($quotations as $quotation)
                <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4 text-sm">
                    <div>
                        <p class="font-mono text-xs text-slate-500">{{ $quotation->number }}</p>
                        <p class="mt-1 font-semibold text-slate-900">{{ $quotation->formattedTotal() }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-billing-status :status="$quotation->status" />
                        <a href="{{ $quotation->viewUrl() }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">
                            {{ $quotation->awaitsResponse() ? 'Review and respond' : 'View' }}
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
