@props([
    'name' => 'images',
    'label' => 'Photographs',
    'help' => null,
    'existing' => null,
    'deleteRoute' => null,
    'multiple' => true,
    'shrink' => true,
    'maxEdge' => null,
    'inputId' => null,
])

@php
    $maxKb = (int) config('documents.images.maximum_kilobytes', 5120);
    $field = $multiple ? $name.'[]' : $name;
    // A page may carry several of these — the service-pictures screen has one
    // card per service — so the element id can be set independently of the
    // field name, which they all share.
    $inputId ??= $name;
    $shrinkTo = (int) ($maxEdge ?? config('documents.images.browser_longest_edge', 1920));
    $shrinkOver = (int) config('documents.images.browser_shrink_over_kilobytes', 900);

    /*
     * Existing images arrive as one of two shapes. A Document exposes url() as
     * a method and knows its own file name; a TourPackageMedia row stores url
     * as a plain column. Reading whichever is there keeps one component usable
     * for both rather than forcing the models to converge for the sake of a
     * preview.
     */
    $sourceOf = static function (object $image): string {
        if (method_exists($image, 'url')) {
            return (string) $image->url();
        }

        return (string) ($image->url ?? '');
    };

    $captionOf = static fn (object $image): string => (string) (
        $image->original_name ?? $image->alt_text ?? 'Photograph'
    );
@endphp

{{--
    Upload images from the machine you are sitting at.

    A file input, not a URL box. Pasting a URL only works if the picture is
    already hosted somewhere, which for a photograph taken on a phone it is not
    — and it leaves the site depending on someone else's server staying up.

    The whole drop zone is a <label> wrapping a real file input, so clicking it
    opens the picker with no JavaScript at all. Drag-and-drop and the thumbnail
    previews are enhancements layered on top: if Alpine fails to load, uploading
    still works.
--}}
<div x-data="imageUpload({{ $shrink ? 'true' : 'false' }}, {{ $shrinkTo }}, {{ $shrinkOver }})" class="space-y-3">
    <label for="{{ $inputId }}" class="block text-sm font-semibold text-ink-800">{{ $label }}</label>

    <label for="{{ $inputId }}"
           @dragover.prevent="dragging = true"
           @dragleave.prevent="dragging = false"
           @drop.prevent="drop($event)"
           :class="dragging ? 'border-brand-600 bg-brand-50' : 'border-ink-300 bg-ink-50'"
           class="flex cursor-pointer flex-col items-center justify-center rounded-card border-2 border-dashed px-6 py-8 text-center transition hover:border-brand-500 hover:bg-brand-50/50">
        <x-icon name="image" class="h-7 w-7 text-brand-700" />
        <span class="mt-2 text-sm font-bold text-ink-800">
            Drag {{ $multiple ? 'photographs' : 'a photograph' }} here, or click to choose
        </span>
        <span class="mt-1 text-xs text-ink-500">
            From your computer, phone or anywhere you can browse to &middot;
            JPG, PNG or WebP &middot; up to {{ round($maxKb / 1024, 1) }} MB each
        </span>
    </label>

    <input id="{{ $inputId }}" name="{{ $field }}" type="file" x-ref="input"
           @if ($multiple) multiple @endif
           accept="image/jpeg,image/png,image/webp"
           class="sr-only" @change="take($event.target.files)">

    @if ($help)
        <p class="text-xs text-ink-500">{{ $help }}</p>
    @endif

    {{--
        What the browser did to the pictures before sending them.

        Worth saying out loud: somebody who has just chosen a 6 MB photograph
        from their phone and sees "ready to upload" should be able to tell that
        it is not about to spend four minutes uploading it.
    --}}
    <template x-if="working">
        <p class="flex items-center gap-2 text-xs font-semibold text-brand-800" role="status">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
            Preparing your photographs…
        </p>
    </template>

    <template x-if="! working && saved > 0">
        <p class="text-xs font-semibold text-brand-800" role="status">
            Resized for the web — uploading <span x-text="savedLabel"></span> instead of the full-size originals.
        </p>
    </template>

    @error($name)
        <p class="text-sm font-medium text-rose-700" role="alert">{{ $message }}</p>
    @enderror
    @error($name.'.*')
        <p class="text-sm font-medium text-rose-700" role="alert">{{ $message }}</p>
    @enderror

    {{-- Chosen but not yet uploaded, previewed straight from the browser. --}}
    <template x-if="previews.length">
        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-ink-600">
                <span x-text="previews.length"></span> ready to upload
            </p>
            <div class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                <template x-for="(preview, index) in previews" :key="index">
                    <figure class="overflow-hidden rounded-control border border-ink-200 bg-white">
                        <div class="aspect-square overflow-hidden bg-ink-100">
                            <img :src="preview.src" :alt="preview.name" class="h-full w-full object-cover">
                        </div>
                        <figcaption class="truncate px-2 py-1 text-[11px] text-ink-600" x-text="preview.name"></figcaption>
                    </figure>
                </template>
            </div>
        </div>
    </template>

    {{-- Already saved against this record. --}}
    @if ($existing && $existing->isNotEmpty())
        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-ink-600">
                {{ $existing->count() }} already uploaded
            </p>
            <div class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                @foreach ($existing as $image)
                    <figure class="overflow-hidden rounded-control border border-ink-200 bg-white">
                        <div class="aspect-square overflow-hidden bg-ink-100">
                            <img src="{{ $sourceOf($image) }}" alt="{{ $captionOf($image) }}" loading="lazy"
                                 class="h-full w-full object-cover">
                        </div>
                        <figcaption class="space-y-1 px-2 py-1.5">
                            <p class="truncate text-[11px] text-ink-600" title="{{ $captionOf($image) }}">
                                {{ $captionOf($image) }}
                            </p>
                            @if ($deleteRoute)
                                {{-- Its own form: a delete button nested inside the
                                     surrounding edit form would submit that instead. --}}
                                <button type="button" form="delete-image-{{ $image->id }}"
                                        class="w-full rounded-control border border-rose-300 px-1 py-0.5 text-[11px] font-bold text-rose-700 hover:bg-rose-50">
                                    Remove
                                </button>
                            @endif
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    @endif
</div>

@if ($existing && $existing->isNotEmpty() && $deleteRoute)
    @push('scripts')
        @foreach ($existing as $image)
            <form id="delete-image-{{ $image->id }}" method="POST"
                  action="{{ $deleteRoute($image) }}"
                  onsubmit="return confirm('Remove this photograph?');" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endforeach
    @endpush
@endif

@once
    @push('scripts')
        <script>
            /*
             * Photographs are shrunk in the browser before they are uploaded.
             *
             * This is the fix for the 504 Gateway Timeout on saving a tour. A
             * photograph off a modern phone is 3-6 MB at around 4000x3000, and
             * a tour takes twelve of them: sixty megabytes of request body over
             * a domestic Ugandan upstream, which is several minutes. The proxy
             * in front of PHP gives up long before that and returns a 504 —
             * having already spent the customer's data, and with nothing saved.
             *
             * At 1920px on the longest edge, which is larger than any place the
             * site displays a photograph, the same twelve pictures come to about
             * four megabytes. The upload finishes in seconds and the picture is
             * indistinguishable on screen.
             *
             * Everything here degrades safely. If canvas, toBlob or DataTransfer
             * is unavailable, or a file fails to decode, the original file is
             * uploaded exactly as before and the server's own limits still apply.
             */
            function imageUpload(shrink, longestEdge, shrinkOverKb) {
                return {
                    previews: [],
                    dragging: false,
                    working: false,
                    saved: 0,
                    get savedLabel() {
                        const mb = this.saved / (1024 * 1024);

                        return mb >= 1 ? `${mb.toFixed(1)} MB` : `${Math.round(this.saved / 1024)} KB`;
                    },

                    take(list) {
                        this.render(list);
                        this.prepare(Array.from(list));
                    },

                    drop(event) {
                        this.dragging = false;
                        // Assigning to .files is what makes a dropped file part
                        // of the form submission; without it the drop is visual
                        // only and nothing uploads.
                        this.$refs.input.files = event.dataTransfer.files;
                        this.take(event.dataTransfer.files);
                    },

                    render(list) {
                        this.previews = Array.from(list).map((file) => ({
                            name: file.name,
                            src: URL.createObjectURL(file),
                        }));
                    },

                    supported() {
                        return shrink
                            && typeof window.DataTransfer === 'function'
                            && typeof document.createElement('canvas').toBlob === 'function'
                            && typeof window.createImageBitmap === 'function';
                    },

                    async prepare(files) {
                        if (! this.supported() || files.length === 0) {
                            return;
                        }

                        this.working = true;
                        this.saved = 0;

                        const before = files.reduce((total, file) => total + file.size, 0);
                        let processed;

                        try {
                            processed = await Promise.all(files.map((file) => this.shrinkOne(file)));
                        } catch (error) {
                            // Whatever went wrong, the originals are still in the
                            // input and still upload. Never block the save.
                            this.working = false;

                            return;
                        }

                        const after = processed.reduce((total, file) => total + file.size, 0);

                        if (after < before) {
                            const bag = new DataTransfer();
                            processed.forEach((file) => bag.items.add(file));
                            this.$refs.input.files = bag.files;
                            this.saved = before - after;
                        }

                        this.working = false;
                    },

                    async shrinkOne(file) {
                        const types = {
                            'image/jpeg': 0.82,
                            'image/webp': 0.85,
                            // PNG is re-encoded as PNG rather than JPEG: a logo
                            // or a screenshot with transparency would otherwise
                            // come back with a black background. The saving comes
                            // from the smaller canvas either way.
                            'image/png': undefined,
                        };

                        if (! (file.type in types)) {
                            return file;
                        }

                        // Already small and modest in size: leave it exactly as
                        // it is rather than re-encoding it and losing quality
                        // for no benefit.
                        if (file.size <= shrinkOverKb * 1024) {
                            return file;
                        }

                        let bitmap;

                        try {
                            bitmap = await createImageBitmap(file);
                        } catch (error) {
                            return file;
                        }

                        const scale = Math.min(1, longestEdge / Math.max(bitmap.width, bitmap.height));
                        const width = Math.max(1, Math.round(bitmap.width * scale));
                        const height = Math.max(1, Math.round(bitmap.height * scale));

                        const canvas = document.createElement('canvas');
                        canvas.width = width;
                        canvas.height = height;
                        canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
                        bitmap.close?.();

                        const blob = await new Promise((resolve) => {
                            canvas.toBlob(resolve, file.type, types[file.type]);
                        });

                        // A re-encode that came out bigger is a re-encode worth
                        // throwing away.
                        if (! blob || blob.size >= file.size) {
                            return file;
                        }

                        // The name is kept, extension and all. The server reads
                        // the real bytes to decide the type and then checks the
                        // extension agrees with them, so changing either one
                        // here would get the file rejected on arrival.
                        return new File([blob], file.name, {
                            type: file.type,
                            lastModified: Date.now(),
                        });
                    },
                };
            }
        </script>
    @endpush
@endonce
