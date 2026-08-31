<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Leasing</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">New agreement</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.leasing.leases.store') }}" class="space-y-6">
                @csrf
                @include('admin.leasing.leases.partials.form', ['lease' => null])

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Save as draft
                    </button>
                    <a href="{{ route('admin.leasing.leases.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
