@php
    use App\Enums\PostStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Journal</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $post->title }}</h1>
                <p class="mt-1 font-mono text-xs text-slate-500">/blog/{{ $post->slug }}</p>
            </div>
            <a href="{{ route('admin.posts.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to articles</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="publishing">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="publishing" class="text-lg font-black text-slate-950">Publishing</h2>
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-bold',
                        'bg-slate-100 text-slate-700' => $post->status->tone() === 'slate',
                        'bg-amber-50 text-amber-900' => $post->status->tone() === 'amber',
                        'bg-emerald-50 text-emerald-800' => $post->status->tone() === 'emerald',
                        'bg-rose-50 text-rose-800' => $post->status->tone() === 'rose',
                    ])>{{ $post->status->label() }}</span>
                </div>

                @if ($post->published_at)
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $post->status === PostStatus::Scheduled ? 'Goes live' : 'Published' }}
                        {{ $post->published_at->timezone($timezone)->format('j M Y, H:i') }} ({{ $timezone }})
                    </p>
                @endif

                @if ($post->isPublishedAt())
                    <a href="{{ route('blog.show', $post->slug) }}" class="mt-3 inline-block text-sm font-bold text-emerald-800 underline">View it on the site</a>
                @endif

                <div class="mt-5 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    @if ($post->status !== PostStatus::Archived)
                        <form method="POST" action="{{ route('admin.posts.publish', $post) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label for="publish_at" class="block text-xs font-semibold text-slate-700">Go live at (blank = now)</label>
                                <input id="publish_at" name="publish_at" type="datetime-local"
                                       class="mt-1 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">
                                {{ $post->status === PostStatus::Published ? 'Reschedule' : 'Publish' }}
                            </button>
                        </form>
                    @endif

                    @if ($post->status === PostStatus::Published || $post->status === PostStatus::Scheduled)
                        <form method="POST" action="{{ route('admin.posts.unpublish', $post) }}">
                            @csrf
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">Take off the site</button>
                        </form>
                    @endif

                    @if ($post->status !== PostStatus::Archived)
                        <form method="POST" action="{{ route('admin.posts.archive', $post) }}"
                              onsubmit="return confirm('Archive this article? It becomes read-only until restored.');">
                            @csrf
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-rose-300 px-4 text-sm font-bold text-rose-700 hover:bg-rose-50">Archive</button>
                        </form>
                    @endif
                </div>
            </section>

            @if ($post->status === PostStatus::Archived)
                <div class="rounded-3xl border border-slate-200 bg-stone-50 p-6">
                    <h2 class="text-lg font-black text-slate-950">This article is archived</h2>
                    <p class="mt-2 text-sm text-slate-700">It is read-only. Restore it to a draft to edit it again.</p>
                </div>
            @else
                @include('admin.posts.partials.form')
            @endif
        </div>
    </div>
</x-app-layout>
