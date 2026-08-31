@extends('layouts.public')

@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $title = $category ? $category->name.' — Journal' : 'Journal';
    $description = 'Travel notes, route guides, and practical advice from the PISFA team in Uganda.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Journal</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black sm:text-5xl">
                {{ $category?->name ?? 'Notes from the road' }}
            </h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">
                {{ $category?->description ?? 'Route guides, packing lists, and practical advice from the people who run the trips.' }}
            </p>
        </div>
    </section>

    @if ($featured->isNotEmpty() && ! $category && ! $tag && blank($search))
        <section class="bg-amber-50 px-4 py-12 sm:px-6 lg:px-8" aria-labelledby="featured-heading">
            <div class="mx-auto max-w-7xl">
                <h2 id="featured-heading" class="text-2xl font-black text-emerald-950">Featured</h2>
                <div class="mt-6 grid gap-6 md:grid-cols-3">
                    @foreach ($featured as $item)
                        @include('blog.partials.card', ['post' => $item, 'timezone' => $timezone])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <form method="GET" action="{{ route('blog.index') }}" class="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-3 sm:items-end">
                <div class="sm:col-span-2">
                    <label for="blog-q" class="block text-sm font-semibold">Search articles</label>
                    <input id="blog-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div>
                    <label for="blog-category" class="block text-sm font-semibold">Category</label>
                    <select id="blog-category" name="category" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">All categories</option>
                        @foreach ($categories as $option)
                            <option value="{{ $option->slug }}" @selected($category?->slug === $option->slug)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap gap-2 sm:col-span-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">Search</button>
                    <a href="{{ route('blog.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                </div>
            </form>

            @if ($tag)
                <p class="mt-4 text-sm text-slate-600">
                    Showing articles tagged <span class="font-bold">{{ $tag }}</span>.
                    <a href="{{ route('blog.index') }}" class="font-bold text-emerald-800 underline">Clear</a>
                </p>
            @endif

            @if ($posts->isEmpty())
                <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No articles yet</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Nothing matches. Try a different category, or clear the search.
                    </p>
                </div>
            @else
                <div class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($posts as $item)
                        @include('blog.partials.card', ['post' => $item, 'timezone' => $timezone])
                    @endforeach
                </div>
                <div class="mt-8">{{ $posts->links() }}</div>
            @endif
        </div>
    </section>
@endsection
