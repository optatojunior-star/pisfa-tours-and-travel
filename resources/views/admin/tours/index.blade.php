<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Service catalogue</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Tour packages</h1></div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <a href="{{ route('admin.tour-categories.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Manage categories</a>
                <a href="{{ route('admin.tours.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">Create package</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert"><p class="font-bold">The requested package action could not be completed.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="package-filter-heading">
                <h2 id="package-filter-heading" class="sr-only">Filter tour packages</h2>
                <form method="GET" action="{{ route('admin.tours.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(14rem,1fr)_12rem_12rem_12rem_auto] lg:items-end">
                    <div><label for="admin-package-search" class="block text-sm font-semibold text-slate-800">Search</label><input id="admin-package-search" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Name, slug or destination" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    <div><label for="admin-package-category" class="block text-sm font-semibold text-slate-800">Category</label><select id="admin-package-category" name="category" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>@endforeach</select></div>
                    <div><label for="admin-package-status" class="block text-sm font-semibold text-slate-800">Status</label><select id="admin-package-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">All statuses</option>@foreach (\App\Enums\TourPackageStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>@endforeach</select></div>
                    <div><label for="admin-package-availability" class="block text-sm font-semibold text-slate-800">Departures</label><select id="admin-package-availability" name="availability" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">Any availability</option><option value="upcoming" @selected(($filters['availability'] ?? '') === 'upcoming')>Has upcoming</option><option value="none" @selected(($filters['availability'] ?? '') === 'none')>None upcoming</option></select></div>
                    <div class="flex gap-2"><button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Filter</button><a href="{{ route('admin.tours.index') }}" class="inline-flex min-h-11 items-center rounded-xl px-3 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-100">Reset</a></div>
                </form>
            </section>

            <section aria-labelledby="package-list-heading">
                <div class="flex items-end justify-between gap-4"><div><h2 id="package-list-heading" class="text-xl font-black text-slate-950">Packages</h2><p class="mt-1 text-sm text-slate-500">{{ $packages->total() }} {{ str('package')->plural($packages->total()) }}</p></div></div>

                @if ($packages->isEmpty())
                    <div class="mt-5 rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center"><h3 class="font-bold text-slate-900">No packages match these filters</h3><p class="mt-2 text-sm text-slate-600">Reset the filters or create a new draft package.</p><a href="{{ route('admin.tours.create') }}" class="mt-5 inline-flex rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Create package</a></div>
                @else
                    <div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($packages as $package)
                            @php
                                $statusValue = $package->status->value;
                                $cover = $package->coverMedia;
                                $departureCount = $package->upcoming_departures_count ?? 0;
                            @endphp
                            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="admin-package-{{ $package->id }}">
                                <div class="relative aspect-[16/8] bg-emerald-950">@if ($cover?->url)<img src="{{ $cover->url }}" alt="{{ $cover->alt_text ?: '' }}" class="h-full w-full object-cover">@else<div class="grid h-full place-items-center text-emerald-200" aria-hidden="true"><svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="m3 20 5.5-8 3.5 4 2.5-3L21 20H3Z"/></svg></div>@endif
                                    <span @class(['absolute left-3 top-3 rounded-full px-2.5 py-1 text-xs font-bold shadow-sm', 'bg-slate-100 text-slate-700' => $statusValue === 'draft', 'bg-emerald-100 text-emerald-800' => $statusValue === 'published', 'bg-amber-100 text-amber-900' => $statusValue === 'archived'])>{{ $package->status->label() }}</span>
                                    @if ($package->is_featured)<span class="absolute right-3 top-3 rounded-full bg-amber-400 px-2.5 py-1 text-xs font-bold text-emerald-950">Featured</span>@endif
                                </div>
                                <div class="p-5">
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $package->category?->name }} · {{ $package->destination }}</p>
                                    <h3 id="admin-package-{{ $package->id }}" class="mt-2 text-lg font-black text-slate-950">{{ $package->name }}</h3>
                                    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs"><div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Base price</dt><dd class="mt-1 font-bold text-slate-900">{{ \App\Support\Money::format((int) $package->base_price_minor, $package->currency) }}</dd></div><div class="rounded-xl bg-slate-50 p-3"><dt class="text-slate-500">Upcoming departures</dt><dd class="mt-1 font-bold text-slate-900">{{ $departureCount }}</dd></div></dl>
                                    <div class="mt-5 flex flex-wrap gap-2">
                                        <a href="{{ route('admin.tours.show', ['tourPackage' => $package]) }}" class="inline-flex min-h-10 flex-1 items-center justify-center rounded-xl border border-emerald-200 px-3 py-2 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Manage</a>
                                        <a href="{{ route('admin.tours.edit', ['tourPackage' => $package]) }}" class="inline-flex min-h-10 items-center justify-center rounded-xl border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">Edit</a>
                                        @if ($statusValue === 'draft')
                                            <form method="POST" action="{{ route('admin.tours.publish', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-10 rounded-xl bg-emerald-700 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-800">Publish</button></form>
                                        @elseif ($statusValue === 'published')
                                            <form method="POST" action="{{ route('admin.tours.archive', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-10 rounded-xl bg-amber-100 px-3 py-2 text-sm font-bold text-amber-900 hover:bg-amber-200">Archive</button></form>
                                        @elseif ($statusValue === 'archived')
                                            <form method="POST" action="{{ route('admin.tours.restore', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-10 rounded-xl bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-700">Restore to draft</button></form>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <div class="mt-8">{{ $packages->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
