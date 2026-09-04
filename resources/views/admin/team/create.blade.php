<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Our team</p>
            <h1 class="mt-1 text-2xl font-bold text-ink-950">Add someone to the about page</h1>
            <p class="mt-1 text-sm text-ink-600">
                Nothing goes public until you publish it, so it is safe to save a half-finished profile.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            @include('admin.team.partials.form', ['member' => $member])
        </div>
    </div>
</x-app-layout>
