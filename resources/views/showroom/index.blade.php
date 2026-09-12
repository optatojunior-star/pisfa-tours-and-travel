@extends('layouts.public')

@php
    $title = 'Cars for sale';
    $description = 'Used vehicles for sale in Kampala from PISFA Tours and Travel — inspected, priced in shillings, and ready to view.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Showroom</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black sm:text-5xl">Cars for sale</h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">
                Vehicles from our own fleet and selected stock, inspected before they are listed.
                Prices are what we are asking; tell us what you think it is worth and we will talk.
            </p>
        </div>
    </section>

    @if ($featured->isNotEmpty() && blank($search))
        <section class="bg-amber-50 px-4 py-12 sm:px-6 lg:px-8" aria-labelledby="featured-heading">
            <div class="mx-auto max-w-7xl">
                <h2 id="featured-heading" class="text-2xl font-black text-emerald-950">Pick of the stock</h2>
                <div class="mt-6 grid gap-6 md:grid-cols-3">
                    @foreach ($featured as $item)
                        @include('showroom.partials.card', ['listing' => $item])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <x-filter-panel :action="route('showroom.index')" eyebrow="Cars for sale" heading="Search the showroom"
                            :filters="$filters + ['q' => $search]" submit="Search"
                            note="A price filter only matches cars listed in that currency.">
                <x-slot:primary>
                    <div class="sm:col-span-2">
                        <label for="showroom-q" class="block text-slate-800">Search</label>
                        <input id="showroom-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Make, model, or reference"
                               class="mt-1 block w-full border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                </x-slot:primary>

                <div>
                    <label for="showroom-min" class="block text-slate-800">Price from</label>
                    <input id="showroom-min" name="min_price" type="text" inputmode="numeric" maxlength="24"
                           value="{{ $filters['min_price'] }}" class="mt-1 block w-full border-slate-300">
                </div>
                <div>
                    <label for="showroom-max" class="block text-slate-800">Price to</label>
                    <input id="showroom-max" name="max_price" type="text" inputmode="numeric" maxlength="24"
                           value="{{ $filters['max_price'] }}" class="mt-1 block w-full border-slate-300">
                </div>
                <div>
                    <label for="showroom-currency" class="block text-slate-800">Currency</label>
                    <select id="showroom-currency" name="currency" class="mt-1 block w-full border-slate-300">
                        @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                            <option value="{{ $code }}" @selected($filters['currency'] === $code)>{{ $code }}</option>
                        @endforeach
                    </select>
                </div>
            </x-filter-panel>

            @if ($listings->isEmpty())
                <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing matches</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Try a wider price range, or
                        <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">tell us what you are looking for</a>
                        and we will watch out for it.
                    </p>
                </div>
            @else
                <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($listings as $item)
                        @include('showroom.partials.card', ['listing' => $item])
                    @endforeach
                </div>
                <div class="mt-8">{{ $listings->links() }}</div>
            @endif
        </div>
    </section>
@endsection
