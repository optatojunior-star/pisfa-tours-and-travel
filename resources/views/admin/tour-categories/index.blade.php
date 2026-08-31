<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><a href="{{ route('admin.tours.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4">Back to packages</a><p class="mt-4 text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Tour catalogue</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Categories</h1></div></div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-[22rem_minmax(0,1fr)] lg:items-start lg:px-8">
            <div class="space-y-5 lg:sticky lg:top-24">
                @if (session('success') || session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>@endif
                @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert"><p class="font-bold">The category was not saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="new-category-heading">
                    <h2 id="new-category-heading" class="text-xl font-black text-emerald-950">Create category</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">New categories are active immediately but appear publicly only when they contain a published package.</p>
                    <form method="POST" action="{{ route('admin.tour-categories.store') }}" class="mt-5 space-y-4">
                        @csrf
                        <div><label for="new-category-name" class="block text-sm font-semibold text-slate-800">Name</label><input id="new-category-name" name="name" type="text" required maxlength="100" value="{{ old('name') }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="new-category-slug" class="block text-sm font-semibold text-slate-800">Slug <span class="font-normal text-slate-500">(optional)</span></label><input id="new-category-slug" name="slug" type="text" maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="{{ old('slug') }}" placeholder="generated-from-name" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="new-category-description" class="block text-sm font-semibold text-slate-800">Description <span class="font-normal text-slate-500">(optional)</span></label><textarea id="new-category-description" name="description" rows="3" maxlength="1000" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('description') }}</textarea></div>
                        <div><label for="new-category-order" class="block text-sm font-semibold text-slate-800">Sort order</label><input id="new-category-order" name="sort_order" type="number" min="0" max="10000" value="{{ old('sort_order', 0) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Create category</button>
                    </form>
                </section>
            </div>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="category-list-heading">
                <div class="border-b border-slate-200 px-5 py-5 sm:px-6"><h2 id="category-list-heading" class="text-xl font-black text-slate-950">Existing categories</h2><p class="mt-1 text-sm text-slate-500">{{ $categories->count() }} {{ str('category')->plural($categories->count()) }}</p></div>
                @if ($categories->isEmpty())
                    <div class="px-6 py-16 text-center"><h3 class="font-bold text-slate-900">No tour categories yet</h3><p class="mt-2 text-sm text-slate-600">Create the first category using the form.</p></div>
                @else
                    <div class="divide-y divide-slate-200">
                        @foreach ($categories as $category)
                            <article class="p-5 sm:p-6" aria-labelledby="category-{{ $category->id }}">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div><div class="flex flex-wrap items-center gap-2"><h3 id="category-{{ $category->id }}" class="font-black text-slate-950">{{ $category->name }}</h3><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-50 text-emerald-800' => $category->is_active, 'bg-slate-100 text-slate-600' => ! $category->is_active])>{{ $category->is_active ? 'Active' : 'Inactive' }}</span></div><p class="mt-1 font-mono text-xs text-slate-500">{{ $category->slug }}</p><p class="mt-2 text-xs text-slate-500">{{ $category->tour_packages_count }} total · {{ $category->published_packages_count }} published</p></div>
                                    <form method="POST" action="{{ route('admin.tour-categories.toggle', ['tourCategory' => $category]) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $category->is_active ? 0 : 1 }}"><button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-xl border px-3 py-2 text-sm font-bold {{ $category->is_active ? 'border-amber-200 text-amber-900 hover:bg-amber-50' : 'border-emerald-200 text-emerald-800 hover:bg-emerald-50' }}">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                                </div>
                                <details class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <summary class="cursor-pointer rounded-lg font-bold text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Edit category</summary>
                                    <form method="POST" action="{{ route('admin.tour-categories.update', ['tourCategory' => $category]) }}" class="mt-5 grid gap-4 sm:grid-cols-2">
                                        @csrf @method('PATCH')
                                        <div><label for="category-{{ $category->id }}-name" class="block text-xs font-semibold text-slate-700">Name</label><input id="category-{{ $category->id }}-name" name="name" type="text" required maxlength="100" value="{{ $category->name }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                                        <div><label for="category-{{ $category->id }}-slug" class="block text-xs font-semibold text-slate-700">Slug</label><input id="category-{{ $category->id }}-slug" name="slug" type="text" required maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="{{ $category->slug }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                                        <div class="sm:col-span-2"><label for="category-{{ $category->id }}-description" class="block text-xs font-semibold text-slate-700">Description</label><textarea id="category-{{ $category->id }}-description" name="description" rows="3" maxlength="1000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ $category->description }}</textarea></div>
                                        <div><label for="category-{{ $category->id }}-order" class="block text-xs font-semibold text-slate-700">Sort order</label><input id="category-{{ $category->id }}-order" name="sort_order" type="number" min="0" max="10000" value="{{ $category->sort_order }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                                        <div class="flex items-end"><button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700">Save category</button></div>
                                    </form>
                                </details>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
