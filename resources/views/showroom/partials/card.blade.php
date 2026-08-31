@php
    /** @var \App\Models\VehicleListing $listing */
    $cover = $listing->coverUrl();
@endphp

<article class="flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
    <a href="{{ route('showroom.show', $listing->slug) }}" class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
        <div class="aspect-[16/10] w-full bg-slate-100">
            @if ($cover)
                <img src="{{ $cover }}" alt="{{ $listing->title }}" loading="lazy" class="h-full w-full object-cover">
            @else
                <div class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-400">
                    Photographs coming soon
                </div>
            @endif
        </div>
    </a>

    <div class="flex flex-1 flex-col p-5">
        <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-black leading-tight text-emerald-950">
                <a href="{{ route('showroom.show', $listing->slug) }}" class="hover:underline">{{ $listing->title }}</a>
            </h3>
            <span @class([
                'shrink-0 rounded-full px-2.5 py-1 text-xs font-bold',
                'bg-emerald-100 text-emerald-900' => $listing->status === \App\Enums\ListingStatus::Available,
                'bg-amber-100 text-amber-900' => $listing->status === \App\Enums\ListingStatus::Reserved,
                'bg-sky-100 text-sky-900' => $listing->status === \App\Enums\ListingStatus::Sold,
            ])>{{ $listing->status->label() }}</span>
        </div>

        <p class="mt-2 text-sm text-slate-600">{{ $listing->specSummary() }}</p>

        <p class="mt-4 text-xl font-black text-emerald-800">
            {{ $listing->formattedAskingPrice() }}
            @if ($listing->is_negotiable && $listing->acceptsEnquiries())
                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">negotiable</span>
            @endif
        </p>

        <a href="{{ route('showroom.show', $listing->slug) }}"
           class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
            {{ $listing->acceptsEnquiries() ? 'View and enquire' : 'View details' }}
        </a>
    </div>
</article>
