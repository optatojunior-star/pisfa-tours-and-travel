@php
    /** @var \App\Models\Property $property */
    $cover = $property->coverUrl();
    $summary = $property->reviewSummary;
@endphp

<article class="flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
    <a href="{{ route('accommodation.show', $property->slug) }}" class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
        <div class="aspect-[16/10] w-full bg-slate-100">
            @if ($cover)
                <img src="{{ $cover }}" alt="{{ $property->name }}" loading="lazy" class="h-full w-full object-cover">
            @else
                <div class="flex h-full w-full items-center justify-center text-sm font-semibold text-slate-400">
                    Photographs coming soon
                </div>
            @endif
        </div>
    </a>

    <div class="flex flex-1 flex-col p-5">
        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $property->property_type->label() }}</p>

        <h3 class="mt-1 text-lg font-black leading-tight text-emerald-950">
            <a href="{{ route('accommodation.show', $property->slug) }}" class="hover:underline">{{ $property->name }}</a>
        </h3>

        <p class="mt-1 text-sm font-semibold text-slate-600">{{ $property->locationLabel() }}</p>

        <p class="mt-3 flex-1 text-sm text-slate-600">{{ $property->summary }}</p>

        @if ($summary?->hasReviews())
            <p class="mt-3 text-sm font-semibold text-amber-700">
                {{ $summary->formattedAverage() }}
                <span class="font-normal text-slate-500">from {{ $summary->reviews_count }} {{ Str::plural('review', $summary->reviews_count) }}</span>
            </p>
        @endif

        <a href="{{ route('accommodation.show', $property->slug) }}"
           class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
            See rooms and prices
        </a>
    </div>
</article>
