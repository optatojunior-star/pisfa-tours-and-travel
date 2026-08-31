@php
    use App\Enums\QuotationRequestStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Billing</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">My quotation requests</h1>
            </div>
            <a href="{{ route('request-quotation') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Request a quotation</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($requests->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No requests yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Ask us to price a trip, a vehicle, or a group booking.</p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($requests as $item)
                        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs text-slate-500">{{ $item->reference }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $item->serviceLabel() }}</h2>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Requested {{ $item->created_at->timezone($timezone)->format('j M Y') }}
                                        · {{ $item->quotations_count }} {{ str('quotation')->plural($item->quotations_count) }}
                                    </p>
                                </div>
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-bold',
                                    'bg-sky-50 text-sky-800' => $item->status === QuotationRequestStatus::New,
                                    'bg-amber-50 text-amber-900' => $item->status === QuotationRequestStatus::InReview,
                                    'bg-emerald-50 text-emerald-800' => $item->status === QuotationRequestStatus::Quoted,
                                    'bg-slate-100 text-slate-700' => $item->status === QuotationRequestStatus::Closed,
                                    'bg-rose-50 text-rose-800' => $item->status === QuotationRequestStatus::Cancelled,
                                ])>{{ $item->status->label() }}</span>
                            </div>

                            <div class="mt-5">
                                <a href="{{ route('portal.quotation-requests.show', $item) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div>{{ $requests->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
