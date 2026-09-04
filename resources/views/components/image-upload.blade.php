@props([
    'name' => 'images',
    'label' => 'Photographs',
    'help' => null,
    'existing' => null,
    'deleteRoute' => null,
    'multiple' => true,
])

@php
    $maxKb = (int) config('documents.images.maximum_kilobytes', 5120);
    $field = $multiple ? $name.'[]' : $name;

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
<div x-data="imageUpload()" class="space-y-3">
    <label for="{{ $name }}" class="block text-sm font-semibold text-ink-800">{{ $label }}</label>

    <label for="{{ $name }}"
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

    <input id="{{ $name }}" name="{{ $field }}" type="file" x-ref="input"
           @if ($multiple) multiple @endif
           accept="image/jpeg,image/png,image/webp"
           class="sr-only" @change="take($event.target.files)">

    @if ($help)
        <p class="text-xs text-ink-500">{{ $help }}</p>
    @endif

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
            function imageUpload() {
                return {
                    previews: [],
                    dragging: false,
                    take(list) {
                        this.previews = Array.from(list).map((file) => ({
                            name: file.name,
                            src: URL.createObjectURL(file),
                        }));
                    },
                    drop(event) {
                        this.dragging = false;
                        // Assigning to .files is what makes a dropped file part
                        // of the form submission; without it the drop is visual
                        // only and nothing uploads.
                        this.$refs.input.files = event.dataTransfer.files;
                        this.take(event.dataTransfer.files);
                    },
                };
            }
        </script>
    @endpush
@endonce
