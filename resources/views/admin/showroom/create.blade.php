<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Showroom</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">New listing</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            @if ($vehicle)
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    Prefilled from fleet vehicle <span class="font-bold">{{ $vehicle->registration_plate }}</span>.
                    Everything here is a snapshot — editing it later will not change the fleet record, and
                    changing the fleet record will not change this listing.
                </div>
            @endif

            <form method="POST" action="{{ route('admin.showroom.store') }}" enctype="multipart/form-data" class="space-y-6">
                @csrf
                @include('admin.showroom.partials.form', ['listing' => null])

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Save as draft
                    </button>
                    <a href="{{ route('admin.showroom.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
                <p class="text-sm text-slate-600">
                    A new listing starts as a draft. Nothing is public until you publish it from the listing page.
                </p>
            </form>
        </div>
    </div>
</x-app-layout>
