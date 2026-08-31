{{--
    Roles whose operations screens do not exist yet.

    This says so plainly rather than rendering an empty console: a dashboard
    that looks broken is worse than one that admits what is not built.
--}}
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">PISFA workspace</p>
            <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Dashboard</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
                <h2 class="text-lg font-black text-slate-950">Your workspace is not built yet</h2>
                <p class="mt-3 text-sm leading-6 text-slate-700">
                    Your account is active with the {{ $user->role?->label() ?? 'assigned' }} role, but the screens for
                    it are still in development. Assignments, vehicle checks, and trip history will appear here once
                    driver operations are released.
                </p>
                <p class="mt-3 text-sm leading-6 text-slate-700">
                    In the meantime your account settings and security options are available.
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('profile.security') }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Security settings</a>
                    <a href="{{ route('contact') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Contact the team</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
