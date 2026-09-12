@extends('layouts.public')

@php
    $title = 'Places to stay';
    $description = 'Lodges, hotels, guest houses, and serviced apartments across Uganda, booked through PISFA Tours and Travels.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Accommodation</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black sm:text-5xl">Places to stay</h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">
                Lodges near the parks, hotels in town, and apartments for longer stays — places we have been to
                ourselves, priced in shillings, with somebody at the end of a phone if anything goes wrong.
            </p>
        </div>
    </section>

    <section class="px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            {{-- Two fields only, so both stay in the primary row and the panel
                 needs no collapsed section at all. --}}
            <x-filter-panel :action="route('accommodation.index')" eyebrow="Places to stay"
                            heading="Find somewhere to stay"
                            :filters="['q' => $search, 'region' => $region]" submit="Search">
                <x-slot:primary>
                    <div>
                        <label for="stay-q" class="block text-slate-800">Search</label>
                        <input id="stay-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Name, town, or district"
                               class="mt-1 block w-full border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="stay-region" class="block text-slate-800">Region</label>
                        <select id="stay-region" name="region" class="mt-1 block w-full border-slate-300">
                            <option value="">Anywhere in Uganda</option>
                            @foreach ($regions as $option)
                                <option value="{{ $option }}" @selected($region === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                </x-slot:primary>
            </x-filter-panel>

            @if ($properties->isEmpty())
                <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing matches</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Try a wider search, or
                        <a href="{{ route('request-quotation') }}" class="font-bold text-emerald-800 underline">tell us where you are going</a>
                        and we will find somewhere.
                    </p>
                </div>
            @else
                <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($properties as $item)
                        @include('accommodation.partials.card', ['property' => $item])
                    @endforeach
                </div>
                <div class="mt-8">{{ $properties->links() }}</div>
            @endif
        </div>
    </section>
@endsection
