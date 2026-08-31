<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Corporate</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">New company account</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('admin.corporate.store') }}" class="space-y-6">
                @csrf
                @include('admin.corporate.partials.form', ['account' => null])

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                        Create the account
                    </button>
                    <a href="{{ route('admin.corporate.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-6 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        Cancel
                    </a>
                </div>
                <p class="text-sm text-slate-600">
                    A new account starts as a prospect. Activate it once the terms are signed — only then can it book
                    and be invoiced on credit.
                </p>
            </form>
        </div>
    </div>
</x-app-layout>
