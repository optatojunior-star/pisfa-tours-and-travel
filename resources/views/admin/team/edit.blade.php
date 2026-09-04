<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Our team</p>
                <h1 class="mt-1 text-2xl font-bold text-ink-950">{{ $member->name }}</h1>
                <p class="mt-1 text-sm text-ink-600">{{ $member->role_title }}</p>
            </div>
            <span class="inline-flex self-start rounded-full px-3 py-1 text-xs font-black uppercase tracking-wide ring-1 {{ $member->is_published ? 'bg-emerald-50 text-emerald-800 ring-emerald-200' : 'bg-ink-100 text-ink-600 ring-ink-200' }}">
                {{ $member->is_published ? 'On the site' : 'Not published' }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-card border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('rejected'))
                <div class="rounded-card border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-bold">Some files were not accepted.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach (session('rejected') as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <section class="flex flex-wrap items-center gap-3 rounded-card border border-ink-200 bg-white p-5 shadow-sm">
                @if ($member->is_published)
                    <form method="POST" action="{{ route('admin.team.unpublish', $member) }}">
                        @csrf
                        <button type="submit" class="min-h-11 rounded-control border border-ink-300 px-5 text-sm font-bold text-ink-700 hover:bg-ink-50">
                            Take off the about page
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.team.publish', $member) }}">
                        @csrf
                        <button type="submit" class="min-h-11 rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800">
                            Show on the about page
                        </button>
                    </form>
                @endif

                @can('delete', $member)
                    {{-- Its own form, and a confirmation: this removes the person
                         and deletes their photograph from the disk. --}}
                    <form method="POST" action="{{ route('admin.team.destroy', $member) }}"
                          onsubmit="return confirm('Remove this profile and its photograph completely?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="min-h-11 rounded-control border border-rose-300 px-5 text-sm font-bold text-rose-700 hover:bg-rose-50">
                            Remove entirely
                        </button>
                    </form>
                @endcan
            </section>

            @include('admin.team.partials.form', ['member' => $member])
        </div>
    </div>
</x-app-layout>
