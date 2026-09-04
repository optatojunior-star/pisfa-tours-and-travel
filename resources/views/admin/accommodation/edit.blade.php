<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Accommodation</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Edit {{ $property->name }}</h1>
            </div>
            <a href="{{ route('admin.accommodation.show', $property) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Back to property
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.accommodation.update', $property) }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @method('PATCH')
                @include('admin.accommodation.partials.form')

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Save changes
                    </button>
                    <a href="{{ route('admin.accommodation.show', $property) }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
