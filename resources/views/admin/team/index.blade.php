<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Content</p>
                <h1 class="mt-1 text-2xl font-bold text-ink-950">Our team</h1>
                <p class="mt-1 text-sm text-ink-600">
                    The people shown on the about page. Only published profiles appear there.
                </p>
            </div>
            <a href="{{ route('admin.team.create') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-control bg-brand-700 px-4 text-sm font-bold text-white hover:bg-brand-800">
                Add a person
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-card border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if ($members->isEmpty())
                <div class="rounded-card border border-dashed border-ink-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold text-ink-900">Nobody added yet</h2>
                    <p class="mx-auto mt-2 max-w-md text-sm text-ink-600">
                        The about page currently says what PISFA does but not who does it. Adding
                        two or three people with photographs is the single change that makes it
                        read like a real company.
                    </p>
                    <a href="{{ route('admin.team.create') }}"
                       class="mt-5 inline-flex min-h-11 items-center rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800">
                        Add the first person
                    </a>
                </div>
            @else
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($members as $member)
                        <li class="flex flex-col overflow-hidden rounded-card border border-ink-200 bg-white shadow-sm">
                            <div class="aspect-[4/3] overflow-hidden bg-ink-100">
                                @if ($url = $member->photoUrl())
                                    <img src="{{ $url }}" alt="{{ $member->name }}" loading="lazy"
                                         class="h-full w-full object-cover">
                                @else
                                    <div class="flex h-full w-full items-center justify-center text-ink-400">
                                        <x-icon name="users" class="h-10 w-10" />
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-4">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-black text-ink-950">{{ $member->name }}</p>
                                        <p class="text-sm text-ink-600">{{ $member->role_title }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-black uppercase tracking-wide ring-1 {{ $member->is_published ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-ink-100 text-ink-600 ring-ink-200' }}">
                                        {{ $member->is_published ? 'Live' : 'Draft' }}
                                    </span>
                                </div>

                                @if ($member->summary)
                                    <p class="mt-2 flex-1 text-sm text-ink-600">{{ $member->summary }}</p>
                                @endif

                                <a href="{{ route('admin.team.edit', $member) }}"
                                   class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-control border border-brand-200 text-sm font-bold text-brand-800 hover:bg-brand-50">
                                    Edit profile
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-app-layout>
