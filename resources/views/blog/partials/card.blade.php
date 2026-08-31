@php
    $cover = $post->coverUrl();
@endphp

<article class="flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
    @if ($cover)
        <img src="{{ $cover }}" alt="" class="h-44 w-full object-cover">
    @else
        <div class="h-44 w-full bg-gradient-to-br from-emerald-800 to-emerald-950" aria-hidden="true"></div>
    @endif

    <div class="flex flex-1 flex-col p-6">
        @if ($post->category)
            <p class="text-xs font-black uppercase tracking-wide text-emerald-700">{{ $post->category->name }}</p>
        @endif

        <h3 class="mt-2 text-lg font-black leading-6 text-slate-950">
            <a href="{{ route('blog.show', $post->slug) }}" class="hover:text-emerald-800 focus:outline-none focus-visible:underline">
                {{ $post->title }}
            </a>
        </h3>

        <p class="mt-3 flex-1 text-sm leading-6 text-slate-600">{{ $post->excerpt }}</p>

        <p class="mt-4 text-xs text-slate-500">
            @if ($post->published_at)
                <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->timezone($timezone)->format('j M Y') }}</time>
                ·
            @endif
            {{ $post->reading_minutes }} min read
        </p>
    </div>
</article>
