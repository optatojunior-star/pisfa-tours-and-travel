@props([
    'images' => [],
    'alt' => '',
    'ratio' => 'aspect-[16/10]',
    'heading' => null,
    'headingId' => null,
])

@php
    /*
     * One gallery for tours, vehicles, cars for sale and places to stay.
     *
     * Every one of them previously rendered a grid of thumbnails and nothing
     * else: upload eight photographs of a lodge and the visitor got eight
     * postage stamps, with no way to look at any of them properly. There was no
     * next or previous control anywhere in the system.
     *
     * The pictures are normalised here rather than at each call site, because
     * the four modules store them three different ways — a VehicleMedia row
     * with a `url` column, a Document with a url() method, or a plain string.
     */
    $slides = collect($images)
        ->map(function (mixed $image) use ($alt): ?array {
            if (is_string($image)) {
                return $image === '' ? null : ['src' => $image, 'alt' => $alt, 'caption' => null];
            }

            if (! is_object($image)) {
                return null;
            }

            $src = method_exists($image, 'url') ? (string) $image->url() : (string) ($image->url ?? '');

            if ($src === '') {
                return null;
            }

            return [
                'src' => $src,
                'alt' => (string) ($image->alt_text ?? $image->original_name ?? $alt),
                'caption' => filled($image->caption ?? null) ? (string) $image->caption : null,
            ];
        })
        ->filter()
        ->values();

    $count = $slides->count();
    $galleryId = 'gallery-'.Str::random(6);
@endphp

@if ($count > 0)
    <figure
        {{ $attributes->merge(['class' => 'not-prose']) }}
        @if ($headingId) aria-labelledby="{{ $headingId }}" @else aria-label="{{ $heading ?? 'Photographs' }}" @endif
        role="group"
        @if ($count > 1)
            x-data="pisfaGallery({{ $count }})"
            @keydown.left.prevent="previous()"
            @keydown.right.prevent="next()"
            tabindex="0"
        @endif
    >
        <div class="relative overflow-hidden rounded-3xl bg-ink-100 {{ $ratio }}"
             @if ($count > 1)
                 @touchstart.passive="onTouchStart($event)"
                 @touchend.passive="onTouchEnd($event)"
             @endif>
            @foreach ($slides as $index => $slide)
                <img src="{{ $slide['src'] }}"
                     alt="{{ $slide['alt'] }}"
                     loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                     @if ($count > 1)
                         x-show="current === {{ $index }}"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         @if ($index > 0) style="display:none" @endif
                     @endif
                     class="absolute inset-0 h-full w-full object-cover">
            @endforeach

            @if ($count > 1)
                {{--
                    Back and forward.

                    Real buttons rather than overlay divs, so they are reachable
                    by keyboard and announced by a screen reader, and sized to
                    the 44px touch target the rest of the console uses — these
                    get pressed with a thumb far more often than with a mouse.
                --}}
                <button type="button" @click="previous()"
                        class="absolute left-2 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink-900 shadow-lg backdrop-blur transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 sm:left-3">
                    <span class="sr-only">Previous photograph</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </button>

                <button type="button" @click="next()"
                        class="absolute right-2 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink-900 shadow-lg backdrop-blur transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 sm:right-3">
                    <span class="sr-only">Next photograph</span>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                </button>

                <p class="absolute bottom-3 right-3 rounded-full bg-ink-950/70 px-3 py-1 text-xs font-bold text-white">
                    <span x-text="current + 1">1</span> / {{ $count }}
                </p>

                {{-- Announced to screen readers on change; the count above is decorative. --}}
                <p class="sr-only" aria-live="polite" x-text="`Photograph ${current + 1} of {{ $count }}`"></p>
            @endif
        </div>

        @if ($count > 1)
            {{-- Thumbnails: a scroll strip on a phone, a row on a desktop. --}}
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Choose a photograph">
                @foreach ($slides as $index => $slide)
                    <button type="button" role="tab"
                            @click="go({{ $index }})"
                            :aria-selected="current === {{ $index }} ? 'true' : 'false'"
                            :class="current === {{ $index }} ? 'ring-2 ring-brand-700 ring-offset-2' : 'opacity-70 hover:opacity-100'"
                            class="size-16 shrink-0 overflow-hidden rounded-control bg-ink-100 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 sm:size-20">
                        <span class="sr-only">Photograph {{ $index + 1 }}</span>
                        <img src="{{ $slide['src'] }}" alt="" loading="lazy" class="h-full w-full object-cover">
                    </button>
                @endforeach
            </div>
        @endif

        @php($captions = $slides->pluck('caption'))
        @if ($captions->filter()->isNotEmpty())
            <figcaption class="mt-2 text-sm leading-6 text-ink-600">
                @if ($count > 1)
                    @foreach ($slides as $index => $slide)
                        <span x-show="current === {{ $index }}" @if ($index > 0) style="display:none" @endif>{{ $slide['caption'] }}</span>
                    @endforeach
                @else
                    {{ $slides->first()['caption'] }}
                @endif
            </figcaption>
        @endif
    </figure>

    @once
        @push('scripts')
            <script>
                function pisfaGallery(count) {
                    return {
                        current: 0,
                        count: count,
                        touchStart: null,
                        go(index) {
                            this.current = (index + this.count) % this.count;
                        },
                        next() {
                            this.go(this.current + 1);
                        },
                        previous() {
                            this.go(this.current - 1);
                        },
                        onTouchStart(event) {
                            this.touchStart = event.changedTouches[0].screenX;
                        },
                        // Most visitors here are on a phone, where the expected
                        // gesture is a swipe rather than a tap on a small arrow.
                        // 45px of travel is enough to distinguish a deliberate
                        // swipe from a thumb resting on the picture while
                        // scrolling the page.
                        onTouchEnd(event) {
                            if (this.touchStart === null) {
                                return;
                            }

                            const travelled = event.changedTouches[0].screenX - this.touchStart;
                            this.touchStart = null;

                            if (Math.abs(travelled) < 45) {
                                return;
                            }

                            travelled < 0 ? this.next() : this.previous();
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endif
