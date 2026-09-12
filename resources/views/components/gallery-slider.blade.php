@props([
    'images' => [],
    'heading' => 'Uganda, as our customers saw it',
    'intro' => null,
])

@php
    $slides = collect($images)
        ->map(fn (object $image): array => [
            'src' => (string) $image->url(),
            'caption' => (string) ($image->metadata['caption'] ?? ''),
        ])
        ->filter(fn (array $slide): bool => $slide['src'] !== '')
        ->values();

    $count = $slides->count();
@endphp

{{--
    The home-page gallery.

    Nothing at all when it is empty — no heading, no placeholder, no "gallery
    coming soon". A section that announces its own emptiness is worse than a
    section that is not there, and this one fills up the day somebody uploads a
    photograph under Content → Home page gallery.

    One picture is not a slideshow, so a single upload renders as a plain wide
    image with no controls to press.
--}}
@if ($count > 0)
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 sm:py-20 lg:px-8" aria-labelledby="gallery-heading">
        <div class="mx-auto max-w-7xl">
            <div class="max-w-3xl">
                <p class="text-xs font-black uppercase tracking-[0.2em] text-amber-300 sm:text-sm">Gallery</p>
                <h2 id="gallery-heading" class="mt-3 text-3xl font-black sm:text-4xl">{{ $heading }}</h2>
                @if ($intro)
                    <p class="mt-4 text-base leading-7 text-emerald-100 sm:text-lg">{{ $intro }}</p>
                @endif
            </div>

            <div class="mt-10"
                 @if ($count > 1)
                     x-data="pisfaGallerySlider({{ $count }})"
                     x-init="start()"
                     @mouseenter="pause()" @mouseleave="resume()"
                     @focusin="pause()" @focusout="resume()"
                     @keydown.left.prevent="previous()" @keydown.right.prevent="next()"
                     role="group" aria-roledescription="carousel" aria-label="{{ $heading }}"
                 @endif>

                <div class="relative aspect-[16/9] overflow-hidden rounded-3xl bg-emerald-900 sm:aspect-[21/9]"
                     @if ($count > 1)
                         @touchstart.passive="onTouchStart($event)"
                         @touchend.passive="onTouchEnd($event)"
                     @endif>
                    @foreach ($slides as $index => $slide)
                        <figure class="absolute inset-0"
                                @if ($count > 1)
                                    x-show="current === {{ $index }}"
                                    x-transition:enter="transition ease-out duration-500"
                                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                                    @if ($index > 0) style="display:none" @endif
                                @endif>
                            <img src="{{ $slide['src'] }}" alt="{{ $slide['caption'] ?: 'PISFA gallery photograph' }}"
                                 loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                                 class="h-full w-full object-cover">

                            @if ($slide['caption'] !== '')
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-emerald-950 via-emerald-950/70 to-transparent p-5 pt-16 sm:p-7 sm:pt-20">
                                    <figcaption class="text-base font-bold sm:text-lg">{{ $slide['caption'] }}</figcaption>
                                </div>
                            @endif
                        </figure>
                    @endforeach

                    @if ($count > 1)
                        <button type="button" @click="previous()"
                                class="absolute left-3 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-emerald-950/60 text-white backdrop-blur transition hover:bg-emerald-950/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                            <span class="sr-only">Previous photograph</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                        </button>
                        <button type="button" @click="next()"
                                class="absolute right-3 top-1/2 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-emerald-950/60 text-white backdrop-blur transition hover:bg-emerald-950/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                            <span class="sr-only">Next photograph</span>
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                        </button>

                        <p class="sr-only" aria-live="polite" x-text="`Photograph ${current + 1} of {{ $count }}`"></p>
                    @endif
                </div>

                @if ($count > 1)
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
                        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Choose a photograph">
                            @foreach ($slides as $index => $slide)
                                <button type="button" role="tab" @click="go({{ $index }})"
                                        :aria-selected="current === {{ $index }} ? 'true' : 'false'"
                                        :class="current === {{ $index }} ? 'w-8 bg-amber-400' : 'w-2.5 bg-white/40 hover:bg-white/70'"
                                        class="h-2.5 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-emerald-950">
                                    <span class="sr-only">Photograph {{ $index + 1 }}</span>
                                </button>
                            @endforeach
                        </div>

                        {{-- An animation a visitor cannot stop fails WCAG 2.2.2. --}}
                        <button type="button" @click="toggle()"
                                class="grid size-11 place-items-center rounded-full border border-white/30 text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                            <span class="sr-only" x-text="playing ? 'Pause the gallery' : 'Play the gallery'">Pause the gallery</span>
                            <svg x-show="playing" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                            <svg x-show="! playing" style="display:none" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5l11 7-11 7z"/></svg>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </section>

    @if ($count > 1)
        @push('scripts')
            <script>
                function pisfaGallerySlider(count) {
                    return {
                        current: 0,
                        count: count,
                        playing: false,
                        timer: null,
                        touchStart: null,
                        start() {
                            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                                return;
                            }

                            this.play();
                        },
                        play() {
                            this.playing = true;
                            clearInterval(this.timer);
                            // Slower than the header: this one is looked at
                            // rather than read, and a photograph needs a moment.
                            this.timer = setInterval(() => this.next(), 5000);
                        },
                        stop() {
                            this.playing = false;
                            clearInterval(this.timer);
                        },
                        toggle() {
                            this.playing ? this.stop() : this.play();
                        },
                        pause() {
                            clearInterval(this.timer);
                        },
                        resume() {
                            if (this.playing) {
                                this.play();
                            }
                        },
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
    @endif
@endif
