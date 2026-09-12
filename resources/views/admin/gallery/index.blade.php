<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Content</p>
            <h1 class="mt-1 text-2xl font-bold text-ink-950">Home page gallery</h1>
            <p class="mt-1 text-sm text-ink-600">
                Photographs that slide across the home page. Use your best wide shots — this is what a visitor
                judges PISFA on before they read a word.
            </p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-card border border-brand-200 bg-brand-50 p-4 text-sm font-semibold text-brand-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('rejected'))
                <div class="rounded-card border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-bold">Some photographs were not accepted:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach (session('rejected') as $reason)<li>{{ $reason }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @error('images')
                <div class="rounded-card border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900" role="alert">{{ $message }}</div>
            @enderror

            <section class="rounded-card border border-ink-200 bg-white p-6" aria-labelledby="gallery-upload-heading">
                <h2 id="gallery-upload-heading" class="text-sm font-bold uppercase tracking-wide text-ink-600">Add photographs</h2>

                <form method="POST" action="{{ route('admin.gallery.store') }}" enctype="multipart/form-data" class="mt-4">
                    @csrf
                    <x-image-upload
                        name="images"
                        input-id="gallery-images"
                        label="Gallery photographs"
                        help="Landscape shots work best — the slider is wide. Up to 12 at a time, and they are resized for the web before they are sent." />

                    <button type="submit" class="mt-4 min-h-11 rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800">
                        Add to the gallery
                    </button>
                </form>
            </section>

            <section aria-labelledby="gallery-list-heading">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <h2 id="gallery-list-heading" class="text-lg font-black text-ink-950">
                        {{ $images->count() }} {{ Str::plural('photograph', $images->count()) }} in the gallery
                    </h2>
                    @if ($images->isNotEmpty())
                        <a href="{{ route('home') }}" target="_blank" rel="noopener"
                           class="text-sm font-bold text-brand-800 underline">See it on the home page</a>
                    @endif
                </div>

                @if ($images->isEmpty())
                    <div class="mt-4 rounded-card border border-dashed border-ink-300 bg-white p-10 text-center">
                        <p class="text-sm text-ink-600">
                            No photographs yet. The gallery section stays hidden on the home page until you add one,
                            so nothing looks broken in the meantime.
                        </p>
                    </div>
                @else
                    <p class="mt-1 text-sm text-ink-600">
                        They slide in the order shown, which is the order they were uploaded.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($images as $index => $image)
                            <figure class="overflow-hidden rounded-card border border-ink-200 bg-white">
                                <div class="aspect-[16/10] overflow-hidden bg-ink-100">
                                    <img src="{{ $image->url() }}"
                                         alt="{{ $image->metadata['caption'] ?? 'Gallery photograph '.($index + 1) }}"
                                         loading="lazy" class="h-full w-full object-cover">
                                </div>

                                <figcaption class="space-y-3 p-3">
                                    <p class="text-xs font-semibold text-ink-500">
                                        #{{ $index + 1 }} &middot; {{ $image->humanSize() }}
                                    </p>

                                    {{-- The caption is optional, and a good one
                                         does a lot of work: a photograph of the
                                         Nile labelled "Murchison Falls" sells a
                                         tour, the same photograph unlabelled is
                                         wallpaper. --}}
                                    <form method="POST" action="{{ route('admin.gallery.caption', $image) }}" class="space-y-2">
                                        @csrf @method('PATCH')
                                        <label for="caption-{{ $image->id }}" class="block text-xs font-bold uppercase tracking-wide text-ink-600">
                                            Caption <span class="font-normal normal-case text-ink-400">(optional)</span>
                                        </label>
                                        <input id="caption-{{ $image->id }}" name="caption" type="text" maxlength="160"
                                               value="{{ $image->metadata['caption'] ?? '' }}"
                                               placeholder="Murchison Falls, on the Nile"
                                               class="block min-h-10 w-full rounded-control border-ink-300 text-sm focus:border-brand-700 focus:ring-brand-700">
                                        <button type="submit" class="text-xs font-bold text-brand-800 underline">Save caption</button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.gallery.destroy', $image) }}"
                                          onsubmit="return confirm('Remove this photograph from the gallery?');"
                                          class="border-t border-ink-200 pt-2">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-bold text-rose-700 underline">Remove</button>
                                    </form>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
