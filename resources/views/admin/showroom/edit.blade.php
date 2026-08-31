<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Showroom</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Edit {{ $listing->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $listing->reference }}</p>
            </div>
            <a href="{{ route('admin.showroom.show', $listing) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Back to listing
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.showroom.update', $listing) }}" class="space-y-6">
                @csrf
                @method('PATCH')
                @include('admin.showroom.partials.form')

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Save changes
                    </button>
                    <a href="{{ route('admin.showroom.show', $listing) }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
