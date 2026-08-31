<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Accommodation</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">New property</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.accommodation.store') }}" class="space-y-6">
                @csrf
                @include('admin.accommodation.partials.form', ['property' => null])

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Save as draft
                    </button>
                    <a href="{{ route('admin.accommodation.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
                <p class="text-sm text-slate-600">
                    A new property starts as a draft. Add at least one room with a price before it can be published —
                    a property with nothing to sell would render a booking form that can never succeed.
                </p>
            </form>
        </div>
    </div>
</x-app-layout>
