<x-app-layout>
    <x-slot name="header">
        <div><a href="{{ route('admin.tours.show', ['tourPackage' => $package]) }}" class="text-sm font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4">Back to package</a><p class="mt-4 text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Tours &amp; safaris</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Edit {{ $package->name }}</h1></div>
    </x-slot>
    <div class="py-8 sm:py-10"><div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">@include('admin.tours.partials.form', ['package' => $package])</div></div>
</x-app-layout>
