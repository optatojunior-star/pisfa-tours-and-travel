<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Content</p>
            <h1 class="mt-1 text-2xl font-bold text-ink-950">Image library</h1>
            <p class="mt-1 text-sm text-ink-600">Upload photographs once and reuse them anywhere on the site.</p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-card border border-brand-200 bg-brand-50 p-4 text-sm font-semibold text-brand-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('rejected'))
                <div class="rounded-card border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-bold">Some files were not accepted:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach (session('rejected') as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @error('images')
                <div class="rounded-card border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900" role="alert">{{ $message }}</div>
            @enderror

            {{-- Upload --}}
            <section class="rounded-card border border-ink-200 bg-white p-6" aria-labelledby="upload-heading">
                <h2 id="upload-heading" class="text-sm font-bold uppercase tracking-wide text-ink-600">Upload images</h2>

                {{--
                    The shared upload component, not a second copy of it.

                    This screen had its own drop zone and its own Alpine object
                    doing the same job slightly differently — which is how it
                    came to be the one uploader in the system that never learned
                    to shrink a photograph before sending it. At twenty files a
                    time that made it the likeliest place in the console to hit
                    a gateway timeout.
                --}}
                <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="mt-4">
                    @csrf

                    <x-image-upload
                        name="images"
                        label="Images to upload"
                        help="Up to 20 at a time. Large photographs are resized for the web before they are sent." />

                    <div class="mt-4 flex flex-wrap items-end gap-3">
                        <div>
                            <label for="album_id" class="block text-xs font-bold uppercase tracking-wide text-ink-600">Album</label>
                            <select id="album_id" name="album_id"
                                    class="mt-1 min-h-11 rounded-control border-ink-300 text-sm focus:border-brand-700 focus:ring-brand-700">
                                <option value="">Image library</option>
                                @foreach ($albums as $option)
                                    <option value="{{ $option->id }}" @selected($album?->id === $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-primary-button type="submit">Upload</x-primary-button>
                    </div>
                </form>
            </section>

            {{-- Filter --}}
            <form method="GET" action="{{ route('admin.media.index') }}"
                  class="flex flex-col gap-3 rounded-card border border-ink-200 bg-white p-4 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="q" class="block text-xs font-bold uppercase tracking-wide text-ink-600">Search by file name</label>
                    <input id="q" name="q" value="{{ $search }}" placeholder="lodge, prado, waterfall"
                           class="mt-1 block min-h-11 w-full rounded-control border-ink-300 text-sm focus:border-brand-700 focus:ring-brand-700">
                </div>
                <div>
                    <label for="album" class="block text-xs font-bold uppercase tracking-wide text-ink-600">Album</label>
                    <select id="album" name="album" class="mt-1 min-h-11 rounded-control border-ink-300 text-sm focus:border-brand-700 focus:ring-brand-700">
                        <option value="">All albums</option>
                        @foreach ($albums as $option)
                            <option value="{{ $option->id }}" @selected($album?->id === $option->id)>
                                {{ $option->name }} ({{ $option->images_count }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-secondary-button type="submit">Filter</x-secondary-button>
            </form>

            {{-- Gallery --}}
            <section aria-labelledby="gallery-heading">
                <h2 id="gallery-heading" class="sr-only">Uploaded images</h2>

                @if ($documents->isEmpty())
                    <div class="rounded-card border border-dashed border-ink-300 bg-white p-12 text-center">
                        <x-icon name="image" class="mx-auto h-10 w-10 text-ink-400" />
                        <p class="mt-3 text-sm font-semibold text-ink-700">No images yet.</p>
                        <p class="mt-1 text-sm text-ink-500">Upload your first photographs above.</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach ($documents as $document)
                            <figure class="group overflow-hidden rounded-card border border-ink-200 bg-white">
                                <div class="aspect-square overflow-hidden bg-ink-100">
                                    <img src="{{ $document->url() }}" alt="{{ $document->original_name }}" loading="lazy"
                                         width="{{ $document->image_width }}" height="{{ $document->image_height }}"
                                         class="h-full w-full object-cover transition group-hover:scale-105">
                                </div>
                                <figcaption class="space-y-2 p-3">
                                    <p class="truncate text-xs font-semibold text-ink-800" title="{{ $document->original_name }}">
                                        {{ $document->original_name }}
                                    </p>
                                    <p class="text-[11px] text-ink-500">
                                        {{ $document->image_width }}&times;{{ $document->image_height }}
                                        &middot; {{ round($document->size_bytes / 1024) }} KB
                                    </p>
                                    <div class="flex items-center gap-2">
                                        {{-- The URL is what somebody actually needs: to paste into a
                                             post, or hand to a designer. --}}
                                        <button type="button" x-data="copyLink(@js($document->url()))" @click="copy()"
                                                class="inline-flex min-h-9 flex-1 items-center justify-center rounded-control border border-ink-300 px-2 py-1 text-xs font-bold text-ink-700 hover:bg-ink-50">
                                            <span x-show="!copied">Copy link</span>
                                            <span x-show="copied" x-cloak class="text-brand-700">Copied</span>
                                        </button>
                                        <form method="POST" action="{{ route('admin.media.destroy', $document) }}"
                                              onsubmit="return confirm('Remove this image? Anywhere it is used will lose it.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex min-h-9 items-center justify-center rounded-control border border-rose-300 px-2 py-1 text-xs font-bold text-rose-700 hover:bg-rose-50">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>

                    <div class="mt-6">{{ $documents->links() }}</div>
                @endif
            </section>
        </div>
    </div>

    @push('scripts')
        <script>
            function copyLink(url) {
                return {
                    copied: false,
                    copy() {
                        navigator.clipboard.writeText(url).then(() => {
                            this.copied = true;
                            setTimeout(() => (this.copied = false), 1500);
                        });
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
