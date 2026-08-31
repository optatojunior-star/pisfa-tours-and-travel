@extends('layouts.public')

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 sm:py-20 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-300">Explore Uganda</p>
            <h1 class="mt-3 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl">Tours and safaris planned with local care</h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">Compare published packages and genuine scheduled departures. Availability and prices are checked again before your request is saved.</p>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-16">
        @if (session('success') || session('status'))
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1">
                <p class="font-bold">Check the catalogue filters.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="tour-filters-heading">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="tour-filters-heading" class="text-xl font-black text-emerald-950">Find your tour</h2>
                    <p class="mt-1 text-sm text-slate-600">Use any combination of filters, then update the results.</p>
                </div>
                @if (collect($filters)->filter(fn ($value) => filled($value))->isNotEmpty())
                    <a href="{{ route('tours.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4 hover:text-emerald-950">Clear all filters</a>
                @endif
            </div>

            <form method="GET" action="{{ route('tours.index') }}" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <label for="tour-search" class="block text-sm font-semibold text-slate-800">Search</label>
                    <input id="tour-search" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Tour name or destination" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div>
                    <label for="tour-category" class="block text-sm font-semibold text-slate-800">Category</label>
                    <select id="tour-category" name="category" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="departure-from" class="block text-sm font-semibold text-slate-800">Departing on or after</label>
                    <input id="departure-from" name="date" type="date" value="{{ $filters['date'] ?? '' }}" min="{{ now(config('pisfa.business_timezone'))->toDateString() }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div>
                    <label for="travelers" class="block text-sm font-semibold text-slate-800">Travelers</label>
                    <input id="travelers" name="party_size" type="number" min="1" max="{{ config('tours.maximum_booking_travelers', 50) }}" inputmode="numeric" value="{{ $filters['party_size'] ?? '' }}" placeholder="Any group size" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><label for="duration-min" class="block text-sm font-semibold text-slate-800">Min days</label><input id="duration-min" name="duration_min" type="number" min="1" max="{{ config('tours.maximum_duration_days', 90) }}" inputmode="numeric" value="{{ $filters['duration_min'] ?? '' }}" placeholder="1" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    <div><label for="duration-max" class="block text-sm font-semibold text-slate-800">Max days</label><input id="duration-max" name="duration_max" type="number" min="1" max="{{ config('tours.maximum_duration_days', 90) }}" inputmode="numeric" value="{{ $filters['duration_max'] ?? '' }}" placeholder="{{ config('tours.maximum_duration_days', 90) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                </div>
                <div><label for="minimum-price" class="block text-sm font-semibold text-slate-800">Minimum base price</label><input id="minimum-price" name="min_price" type="text" inputmode="decimal" value="{{ $filters['min_price'] ?? '' }}" placeholder="No separators" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                <div><label for="maximum-price" class="block text-sm font-semibold text-slate-800">Maximum base price</label><input id="maximum-price" name="max_price" type="text" inputmode="decimal" value="{{ $filters['max_price'] ?? '' }}" placeholder="No separators" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                <div><label for="tour-currency" class="block text-sm font-semibold text-slate-800">Price currency</label><select id="tour-currency" name="currency" aria-describedby="tour-currency-help" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All currencies</option>@foreach (config('tours.currencies', ['UGX', 'USD']) as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select><p id="tour-currency-help" class="mt-1 text-xs text-slate-500">Choose a currency for price limits or lowest-price sorting.</p></div>
                <div>
                    <label for="tour-sort" class="block text-sm font-semibold text-slate-800">Sort by</label>
                    <select id="tour-sort" name="sort" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="recommended" @selected(($filters['sort'] ?? 'recommended') === 'recommended')>Recommended</option>
                        <option value="earliest" @selected(($filters['sort'] ?? '') === 'earliest')>Earliest departure</option>
                        <option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest base price (one currency)</option>
                        <option value="duration" @selected(($filters['sort'] ?? '') === 'duration')>Shortest duration</option>
                        <option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest</option>
                    </select>
                </div>
                <div class="flex items-end sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-6 py-3 font-bold text-white transition hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 sm:w-auto">Update results</button>
                </div>
            </form>
        </section>

        <section class="mt-10" aria-labelledby="tour-results-heading" aria-live="polite">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 id="tour-results-heading" class="text-2xl font-black text-emerald-950">Available packages</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $packages->total() }} {{ str('tour')->plural($packages->total()) }} found</p>
                </div>
                <p class="text-xs text-slate-500">Prices are checked again during booking.</p>
            </div>

            @if ($packages->isEmpty())
                <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                    <svg class="mx-auto h-12 w-12 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m3 20 5.5-8 3.5 4 2.5-3L21 20H3Z"/><path d="M14 7a2 2 0 1 0 4 0 2 2 0 0 0-4 0Z"/></svg>
                    <h3 class="mt-4 text-lg font-bold text-slate-900">No tours match these filters</h3>
                    <p class="mt-2 text-sm text-slate-600">Clear a filter or tell us about the journey you have in mind.</p>
                    <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                        <a href="{{ route('tours.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50">Clear filters</a>
                        <a href="{{ route('request-quotation', ['service' => 'tours-safaris']) }}" class="rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Request a custom tour</a>
                    </div>
                </div>
            @else
                <div class="mt-6 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($packages as $package)
                        @include('tours.partials.package-card', ['package' => $package])
                    @endforeach
                </div>
                <div class="mt-10">{{ $packages->links() }}</div>
            @endif
        </section>
    </div>
@endsection
