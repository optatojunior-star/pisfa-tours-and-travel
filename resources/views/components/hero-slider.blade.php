@props([
    'services' => [],
    'images' => [],
])

@php
    /*
     * The home page header, as a slideshow of what PISFA actually does.
     *
     * The header used to be a fixed headline over a flat green panel: a visitor
     * had to scroll past it to discover that this company hires cars, sells
     * them, imports them, runs safaris, books lodges and meets flights. Every
     * one of those is now a slide, in the same order as the service menu and
     * fed from the same ServiceCatalogue, so a module that ships appears here
     * without anyone remembering to add it.
     *
     * Each slide uses the picture uploaded under Content → Service pictures.
     * A service with no picture keeps the brand panel and its line icon rather
     * than showing a gap, so the slideshow works on the day it is deployed and
     * improves as pictures are added.
     */
    $slides = collect($services)
        ->map(fn (array $service, string $slug): array => [
            'slug' => $slug,
            'name' => $service['name'],
            'summary' => $service['summary'],
            'icon' => $service['icon'],
            'action' => $service['action'] ?? 'Discuss this service',
            'href' => isset($service['route'])
                ? route($service['route'])
                : route('request-quotation', ['service' => $slug]),
            'image' => $images[$slug] ?? null,
            'bookable' => isset($service['route']),
        ])
        ->values();

    $count = $slides->count();
@endphp

@if ($count > 0)
    <section
        class="relative overflow-hidden bg-emerald-950 text-white"
        aria-roledescription="carousel"
        aria-label="PISFA services"
        x-data="pisfaHero({{ $count }})"
        x-init="start()"
        @mouseenter="pause()" @mouseleave="resume()"
        @focusin="pause()" @focusout="resume()"
        @keydown.left.prevent="previous()" @keydown.right.prevent="next()">

        {{-- Backdrop: the current service's photograph, dimmed enough to read over. --}}
        @foreach ($slides as $index => $slide)
            <div x-show="current === {{ $index }}"
                 x-transition:enter="transition ease-out duration-500"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 @if ($index > 0) style="display:none" @endif
                 class="absolute inset-0" aria-hidden="true">
                @if ($slide['image'])
                    <img src="{{ $slide['image'] }}" alt="" loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                         class="h-full w-full object-cover">
                    <div class="absolute inset-0 bg-gradient-to-r from-emerald-950 via-emerald-950/85 to-emerald-950/40"></div>
                @else
                    <div class="h-full w-full bg-emerald-950">
                        <div class="absolute -right-32 -top-32 size-96 rounded-full bg-amber-300 opacity-20 blur-3xl"></div>
                        <div class="absolute -bottom-36 -left-20 size-96 rounded-full bg-emerald-400 opacity-20 blur-3xl"></div>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
            <div class="max-w-3xl">
                <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Discover Uganda with confidence</p>
                <h1 class="mt-5 text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">
                    Journeys planned around people, not packages.
                </h1>

                {{-- The changing part. One group per service, only one shown. --}}
                @foreach ($slides as $index => $slide)
                    <div x-show="current === {{ $index }}"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
                         @if ($index > 0) style="display:none" @endif
                         role="group" aria-roledescription="slide"
                         aria-label="{{ $index + 1 }} of {{ $count }}: {{ $slide['name'] }}"
                         class="mt-8">
                        <div class="flex items-center gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-control bg-amber-400 text-emerald-950">
                                <x-icon :name="$slide['icon']" class="h-6 w-6" />
                            </span>
                            <p class="text-2xl font-black text-white sm:text-3xl">{{ $slide['name'] }}</p>
                        </div>
                        <p class="mt-4 max-w-2xl text-lg leading-8 text-emerald-100">{{ $slide['summary'] }}</p>
                        <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ $slide['href'] }}"
                               class="inline-flex min-h-11 items-center justify-center rounded-xl bg-amber-400 px-6 py-3.5 text-center font-bold text-emerald-950 transition hover:bg-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                                {{ $slide['action'] }}
                            </a>
                            <a href="#services"
                               class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/40 px-6 py-3.5 text-center font-bold text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                                See everything we do
                            </a>
                        </div>
                    </div>
                @endforeach

                {{-- Controls. Kept below the copy so a thumb reaches them on a phone. --}}
                <div class="mt-10 flex flex-wrap items-center gap-3">
                    <button type="button" @click="previous()"
                            class="grid size-11 place-items-center rounded-full border border-white/30 text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                        <span class="sr-only">Previous service</span>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <button type="button" @click="next()"
                            class="grid size-11 place-items-center rounded-full border border-white/30 text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                        <span class="sr-only">Next service</span>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                    </button>

                    {{-- Pause is not decoration: an animation that cannot be
                         stopped fails WCAG 2.2.2, and this one carries links. --}}
                    <button type="button" @click="toggle()"
                            class="grid size-11 place-items-center rounded-full border border-white/30 text-white transition hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">
                        <span class="sr-only" x-text="playing ? 'Pause the slideshow' : 'Play the slideshow'">Pause the slideshow</span>
                        <svg x-show="playing" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                        <svg x-show="! playing" style="display:none" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5l11 7-11 7z"/></svg>
                    </button>

                    <div class="ml-1 flex flex-wrap gap-2" role="tablist" aria-label="Choose a service">
                        @foreach ($slides as $index => $slide)
                            <button type="button" role="tab" @click="go({{ $index }})"
                                    :aria-selected="current === {{ $index }} ? 'true' : 'false'"
                                    :class="current === {{ $index }} ? 'w-8 bg-amber-400' : 'w-2.5 bg-white/40 hover:bg-white/70'"
                                    class="h-2.5 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2 focus-visible:ring-offset-emerald-950">
                                <span class="sr-only">{{ $slide['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <p class="sr-only" aria-live="polite" x-text="announcement"></p>
            </div>
        </div>
    </section>

    @push('scripts')
        <script>
            function pisfaHero(count) {
                return {
                    current: 0,
                    count: count,
                    playing: false,
                    timer: null,
                    names: @json($slides->pluck('name')),
                    get announcement() {
                        return `${this.names[this.current]}, ${this.current + 1} of ${this.count}`;
                    },
                    start() {
                        // Somebody who has asked their device to reduce motion
                        // gets the slides and the controls, but nothing moves on
                        // its own.
                        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                            return;
                        }

                        this.play();
                    },
                    play() {
                        this.playing = true;
                        clearInterval(this.timer);
                        this.timer = setInterval(() => this.next(), 6000);
                    },
                    stop() {
                        this.playing = false;
                        clearInterval(this.timer);
                    },
                    toggle() {
                        this.playing ? this.stop() : this.play();
                    },
                    // Hover and focus only suspend the timer; they must not
                    // change whether the visitor asked for it to be playing.
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
                };
            }
        </script>
    @endpush
@endif
