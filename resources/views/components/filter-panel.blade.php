@props([
    'action',
    'heading' => 'Search',
    'eyebrow' => null,
    'filters' => [],
    'submit' => 'Search',
    'note' => null,
    'ignore' => ['sort', 'per_page', 'page', 'currency'],
])

@php
    /*
     * One compact filter bar for every catalogue.
     *
     * Each page had grown its own always-expanded grid — car hire had eleven
     * fields in four columns — so the search form was taller than the first row
     * of results on a laptop and pushed the actual tours, vehicles and stays
     * below the fold. A visitor arrives wanting to see what is on offer, not to
     * fill in a form.
     *
     * The primary row stays visible. Everything else collapses into a
     * <details>, which matters more than it looks: fields inside a closed
     * <details> are still in the DOM, so they still submit, and the panel
     * re-opens by itself when filters are active — a narrowed result set must
     * never look like an empty catalogue.
     */
    $applied = collect($filters)
        ->reject(fn (mixed $value, string $key): bool => in_array($key, $ignore, true) || blank($value))
        ->count();
@endphp

<form method="GET" action="{{ $action }}"
      class="pisfa-filters rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
        <div class="min-w-0 flex-1">
            @if ($eyebrow)
                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-emerald-700">{{ $eyebrow }}</p>
            @endif
            <h2 class="mt-0.5 text-base font-black text-emerald-950">{{ $heading }}</h2>
        </div>

        {{-- The one or two controls worth showing unprompted. --}}
        <div class="grid min-w-0 flex-[2] gap-3 sm:grid-cols-2">
            {{ $primary ?? '' }}
        </div>

        <div class="flex shrink-0 gap-2">
            <button type="submit"
                    class="inline-flex min-h-10 items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white transition hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                {{ $submit }}
            </button>
            @if ($applied > 0)
                <a href="{{ $action }}"
                   class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                    Clear
                </a>
            @endif
        </div>
    </div>

    @if (trim($slot) !== '')
        <details class="group mt-3 border-t border-slate-200 pt-3" @if ($applied > 0) open @endif>
            <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-bold text-emerald-800">
                <svg class="h-4 w-4 shrink-0 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 18l6-6-6-6"/>
                </svg>
                More filters
                @if ($applied > 0)
                    <span class="rounded-full bg-emerald-800 px-2 py-0.5 text-[11px] font-black text-white">{{ $applied }}</span>
                @endif
            </summary>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {{ $slot }}
            </div>

            @if ($note)
                <p class="mt-3 text-xs leading-5 text-slate-500">{{ $note }}</p>
            @endif

            <button type="submit"
                    class="mt-4 inline-flex min-h-10 items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white transition hover:bg-emerald-900 lg:hidden">
                Apply filters
            </button>
        </details>
    @endif
</form>

@once
    @push('styles')
        <style>
            /*
             * Control sizing for the filter bars, scoped to .pisfa-filters.
             *
             * Done here rather than by editing the class list of every field on
             * five pages: there are roughly forty of them, the pages would drift
             * apart again the first time one was touched, and "make them small
             * in all features" is a single decision that deserves a single
             * place to change it.
             */
            .pisfa-filters label {
                font-size: 0.75rem;
                line-height: 1rem;
                font-weight: 600;
            }

            .pisfa-filters input:not([type="checkbox"]):not([type="radio"]),
            .pisfa-filters select {
                min-height: 2.5rem;
                height: 2.5rem;
                padding-top: 0;
                padding-bottom: 0;
                font-size: 0.875rem;
                line-height: 1.25rem;
                border-radius: 0.625rem;
            }

            /* Helper text under a field is guidance, not content. */
            .pisfa-filters p.text-xs {
                font-size: 0.6875rem;
                line-height: 1rem;
            }
        </style>
    @endpush
@endonce
