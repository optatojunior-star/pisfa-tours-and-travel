@php
    use App\Enums\PostStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Journal</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Articles</h1>
            </div>
            <a href="{{ route('admin.posts.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">New article</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Article counts">
                @foreach (PostStatus::cases() as $case)
                    <a href="{{ route('admin.posts.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="post-filters">
                <h2 id="post-filters" class="sr-only">Filter articles</h2>
                <form method="GET" action="{{ route('admin.posts.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="post-q" class="block text-sm font-semibold">Search</label>
                        <input id="post-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Headline, summary, or slug"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="post-status" class="block text-sm font-semibold">Status</label>
                        <select id="post-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (PostStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.posts.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($posts->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No articles match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a different status, or write the first one.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Journal articles</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Headline</th>
                                <th scope="col" class="px-4 py-3">Category</th>
                                <th scope="col" class="px-4 py-3">Author</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Live</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($posts as $item)
                                <tr class="align-top">
                                    <td class="max-w-sm px-4 py-3">
                                        <p class="font-semibold text-slate-900">{{ $item->title }}</p>
                                        <p class="mt-1 font-mono text-xs text-slate-500">/blog/{{ $item->slug }}</p>
                                        @if ($item->is_featured)
                                            <p class="mt-1 text-[10px] font-bold uppercase text-amber-700">Featured</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ $item->category?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-600">{{ $item->author?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-3 py-1 text-xs font-bold',
                                            'bg-slate-100 text-slate-700' => $item->status->tone() === 'slate',
                                            'bg-amber-50 text-amber-900' => $item->status->tone() === 'amber',
                                            'bg-emerald-50 text-emerald-800' => $item->status->tone() === 'emerald',
                                            'bg-rose-50 text-rose-800' => $item->status->tone() === 'rose',
                                        ])>{{ $item->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        {{ $item->published_at?->timezone($timezone)->format('j M Y, H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.posts.edit', $item) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $posts->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
