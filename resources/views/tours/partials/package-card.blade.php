@php
    $cover = $package->relationLoaded('coverMedia') ? $package->coverMedia : null;
    $scheduledDepartures = $package->relationLoaded('departures')
        ? $package->departures
            ->filter(fn ($item) => ($item->status?->value ?? $item->status) === 'scheduled')
            ->sortBy('starts_at')
            ->values()
        : collect();
    $nextDeparture = $scheduledDepartures->first(function ($item) use ($package): bool {
        $remaining = max(0, (int) $item->capacity - (int) ($item->reserved_seats ?? 0));

        return $item->cancellation_cutoff_at?->isFuture() === true
            && $remaining >= (int) $package->min_travelers;
    }) ?? $scheduledDepartures->first();
    $nextDeparturePrice = $nextDeparture?->price_override_minor;
    $nextDepartureCurrency = $nextDeparturePrice !== null
        ? ($nextDeparture?->currency ?? $package->currency)
        : $package->currency;
    $remainingPlaces = $nextDeparture
        ? max(0, (int) $nextDeparture->capacity - (int) ($nextDeparture->reserved_seats ?? 0))
        : null;
    $cutoffPassed = $nextDeparture?->cancellation_cutoff_at?->isPast() ?? false;
@endphp

<article class="group flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg">
    <a href="{{ route('tours.show', ['tourPackage' => $package]) }}" class="relative block aspect-[16/10] overflow-hidden bg-emerald-950 focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-400 focus-visible:ring-inset" aria-label="View {{ $package->name }}">
        @if ($cover?->url)
            <img src="{{ $cover->url }}" alt="{{ $cover->alt_text ?: $package->name }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
        @else
            <div class="grid h-full place-items-center bg-gradient-to-br from-emerald-800 to-emerald-950 text-emerald-100" aria-hidden="true">
                <svg class="h-14 w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"><path d="m3 20 5.5-8 3.5 4 2.5-3L21 20H3Z"/><path d="M14 7a2 2 0 1 0 4 0 2 2 0 0 0-4 0Z"/></svg>
            </div>
        @endif
        @if ($package->category)
            <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-bold text-emerald-900 shadow-sm">{{ $package->category->name }}</span>
        @endif
        @if ($package->is_featured)
            <span class="absolute right-4 top-4 rounded-full bg-amber-400 px-3 py-1 text-xs font-bold text-emerald-950">Featured</span>
        @endif
    </a>

    <div class="flex flex-1 flex-col p-5 sm:p-6">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">{{ $package->destination }}</p>
        <h2 class="mt-2 text-xl font-black leading-tight text-emerald-950">
            <a href="{{ route('tours.show', ['tourPackage' => $package]) }}" class="rounded hover:text-amber-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">{{ $package->name }}</a>
        </h2>
        @if ($package->summary ?? null)
            <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{{ $package->summary }}</p>
        @endif

        <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
            <div class="rounded-xl bg-slate-50 px-3 py-2">
                <dt class="text-xs text-slate-500">Duration</dt>
                <dd class="mt-0.5 font-bold text-slate-800">{{ $package->duration_days }} {{ str('day')->plural($package->duration_days) }}</dd>
            </div>
            <div class="rounded-xl bg-slate-50 px-3 py-2">
                <dt class="text-xs text-slate-500">Group size</dt>
                <dd class="mt-0.5 font-bold text-slate-800">{{ $package->min_travelers }}–{{ $package->max_travelers }}</dd>
            </div>
        </dl>

        <div class="mt-5 flex flex-1 flex-col justify-end gap-4 border-t border-slate-100 pt-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs text-slate-500">Package base price, per traveler</p>
                <p class="mt-0.5 text-lg font-black text-amber-700">{{ \App\Support\Money::format((int) $package->base_price_minor, $package->currency) }}</p>
                @if ($nextDeparture)
                    @if ($nextDeparturePrice !== null)
                        <p class="mt-1 text-xs font-semibold text-slate-700">Next departure: {{ \App\Support\Money::format((int) $nextDeparturePrice, $nextDepartureCurrency) }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-500">Departs: <time datetime="{{ $nextDeparture->starts_at->toDateString() }}">{{ $nextDeparture->starts_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></p>
                    @if ($remainingPlaces === 0)
                        <p class="mt-1 text-xs font-bold text-rose-700">Sold out</p>
                    @elseif ($cutoffPassed)
                        <p class="mt-1 text-xs font-bold text-amber-800">Booking cutoff has passed</p>
                    @elseif ($remainingPlaces < (int) $package->min_travelers)
                        <p class="mt-1 text-xs font-bold text-amber-800">{{ $remainingPlaces }} {{ str('place')->plural($remainingPlaces) }} left · below the {{ $package->min_travelers }}-traveler minimum</p>
                    @else
                        <p class="mt-1 text-xs font-bold text-emerald-700">{{ $remainingPlaces }} {{ str('place')->plural($remainingPlaces) }} available</p>
                    @endif
                @else
                    <p class="mt-1 text-xs font-medium text-amber-800">No scheduled departure yet</p>
                @endif
            </div>
            <a href="{{ route('tours.show', ['tourPackage' => $package]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2">View tour</a>
        </div>
    </div>
</article>
