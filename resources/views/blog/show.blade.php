@extends('layouts.public')

@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    // Editable SEO, falling back to the post's own words when blank.
    $title = $post->metaTitle();
    $description = $post->metaDescription();
    $cover = $post->coverUrl();
@endphp

@section('content')
    <article>
        <header class="relative overflow-hidden bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
            @if ($cover)
                <img src="{{ $cover }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-40">
                <div class="absolute inset-0 bg-gradient-to-t from-emerald-950 via-emerald-950/70 to-emerald-950/40" aria-hidden="true"></div>
            @endif

            <div class="relative mx-auto max-w-4xl">
                <nav aria-label="Breadcrumb" class="text-sm text-emerald-100">
                    <ol class="flex flex-wrap items-center gap-2">
                        <li><a href="{{ route('home') }}" class="hover:text-amber-300">Home</a></li>
                        <li aria-hidden="true">/</li>
                        <li><a href="{{ route('blog.index') }}" class="hover:text-amber-300">Journal</a></li>
                        @if ($post->category)
                            <li aria-hidden="true">/</li>
                            <li>
                                <a href="{{ route('blog.index', ['category' => $post->category->slug]) }}" class="hover:text-amber-300">{{ $post->category->name }}</a>
                            </li>
                        @endif
                    </ol>
                </nav>

                <h1 class="mt-8 text-4xl font-black tracking-tight sm:text-5xl">{{ $post->title }}</h1>
                <p class="mt-5 text-lg leading-8 text-emerald-50">{{ $post->excerpt }}</p>

                <p class="mt-6 text-sm text-emerald-100">
                    @if ($post->author)
                        {{ $post->author->name }} ·
                    @endif
                    @if ($post->published_at)
                        <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->timezone($timezone)->format('j F Y') }}</time>
                        ·
                    @endif
                    {{ $post->reading_minutes }} min read
                </p>
            </div>
        </header>

        <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            {{-- Escaped, not raw: post bodies are written in the console by
                 staff, but rendering them unescaped would turn an editor
                 account into stored XSS against every reader. --}}
            <div class="space-y-5 text-base leading-7 text-slate-800">
                @foreach (preg_split('/\R{2,}/', trim($post->body)) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>

            @if ($post->tags->isNotEmpty())
                <div class="mt-10 flex flex-wrap gap-2 border-t border-slate-200 pt-6">
                    <span class="text-sm font-bold text-slate-700">Tagged</span>
                    @foreach ($post->tagList() as $tag)
                        <a href="{{ route('blog.index', ['tag' => $tag]) }}"
                           class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-stone-200">
                            {{ $tag }}
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="mt-10 rounded-3xl bg-emerald-50 p-6 ring-1 ring-emerald-100">
                <h2 class="text-lg font-black text-emerald-950">Planning something like this?</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    Tell us what you have in mind and we will price it. Nothing is charged until you accept.
                </p>
                <a href="{{ route('request-quotation') }}"
                   class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
                    Request a quotation
                </a>
            </div>
        </div>
    </article>

    @if ($related->isNotEmpty())
        <section class="bg-stone-50 px-4 py-16 sm:px-6 lg:px-8" aria-labelledby="related-heading">
            <div class="mx-auto max-w-7xl">
                <h2 id="related-heading" class="text-2xl font-black text-emerald-950">More like this</h2>
                <div class="mt-6 grid gap-6 md:grid-cols-3">
                    @foreach ($related as $item)
                        @include('blog.partials.card', ['post' => $item, 'timezone' => $timezone])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
