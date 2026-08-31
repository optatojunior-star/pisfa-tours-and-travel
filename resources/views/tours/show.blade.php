@extends('layouts.public')

@php
    $media = $package->media->sortBy('sort_order');
    $cover = $media->firstWhere('is_cover', true) ?? $media->first();
    $itinerary = $package->itineraryDays->sortBy(fn ($day) => [$day->sort_order, $day->day_number]);
    $inclusions = $package->inclusions->sortBy('sort_order');
    $exclusions = $package->exclusions->sortBy('sort_order');
    $departures = $package->departures->sortBy('starts_at');
    // `publishedReviews` is the only review relation a public page may render.
    $reviews = $package->publishedReviews;
    $ratings = $package->reviewSummary;
@endphp

@section('content')
    <section class="relative overflow-hidden bg-emerald-950 text-white">
        @if ($cover?->url)
            <img src="{{ $cover->url }}" alt="{{ $cover->alt_text ?: $package->name }}" class="absolute inset-0 h-full w-full object-cover opacity-45">
        @endif
        <div class="absolute inset-0 bg-gradient-to-t from-emerald-950 via-emerald-950/70 to-emerald-950/30" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
            <nav aria-label="Breadcrumb" class="text-sm text-emerald-100">
                <ol class="flex flex-wrap items-center gap-2">
                    <li><a href="{{ route('home') }}" class="hover:text-amber-300">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('tours.index') }}" class="hover:text-amber-300">Tours</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page" class="max-w-64 truncate text-white">{{ $package->name }}</li>
                </ol>
            </nav>
            <div class="mt-12 max-w-4xl">
                <div class="flex flex-wrap gap-2 text-xs font-bold">
                    @if ($package->category)<span class="rounded-full bg-white/95 px-3 py-1 text-emerald-900">{{ $package->category->name }}</span>@endif
                    @if ($package->is_featured)<span class="rounded-full bg-amber-400 px-3 py-1 text-emerald-950">Featured</span>@endif
                </div>
                <p class="mt-5 text-sm font-black uppercase tracking-[0.18em] text-amber-300">{{ $package->destination }}</p>
                <h1 class="mt-3 text-4xl font-black tracking-tight sm:text-5xl lg:text-6xl">{{ $package->name }}</h1>
                <p class="mt-6 max-w-3xl text-lg leading-8 text-emerald-50">{{ $package->summary }}</p>
            </div>
        </div>
    </section>

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
        @if (session('success') || session('status'))
            <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>
        @endif

        <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
            <div class="space-y-10">
                <section aria-labelledby="overview-heading">
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-500">Duration</dt><dd class="mt-1 font-black text-emerald-950">{{ $package->duration_days }} {{ str('day')->plural($package->duration_days) }}</dd></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-500">Group size</dt><dd class="mt-1 font-black text-emerald-950">{{ $package->min_travelers }}–{{ $package->max_travelers }}</dd></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-500">Starting price</dt><dd class="mt-1 font-black text-amber-700">{{ \App\Support\Money::format((int) $package->base_price_minor, $package->currency) }}</dd></div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4"><dt class="text-xs text-slate-500">Cancellation cutoff</dt><dd class="mt-1 font-black text-emerald-950">{{ $package->cancellation_cutoff_hours }} hours</dd></div>
                    </dl>
                    <h2 id="overview-heading" class="mt-8 text-2xl font-black text-emerald-950">About this tour</h2>
                    <div class="mt-4 whitespace-pre-line text-base leading-8 text-slate-700">{{ $package->description }}</div>
                </section>

                @if ($media->count() > 1)
                    <section aria-labelledby="gallery-heading">
                        <h2 id="gallery-heading" class="text-2xl font-black text-emerald-950">Gallery</h2>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            @foreach ($media as $image)
                                <figure class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                                    <img src="{{ $image->url }}" alt="{{ $image->alt_text ?: $package->name }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                                    @if ($image->caption)<figcaption class="px-4 py-3 text-sm text-slate-600">{{ $image->caption }}</figcaption>@endif
                                </figure>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($itinerary->isNotEmpty())
                    <section aria-labelledby="itinerary-heading">
                        <h2 id="itinerary-heading" class="text-2xl font-black text-emerald-950">Day-by-day itinerary</h2>
                        <div class="mt-5 space-y-3">
                            @foreach ($itinerary as $day)
                                @php
                                    $activities = is_array($day->activities) ? $day->activities : [];
                                @endphp
                                <details class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @if ($loop->first) open @endif>
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-lg font-bold text-slate-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                                        <span class="flex min-w-0 items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-800 text-sm text-white">{{ $day->day_number }}</span><span>{{ $day->title }}</span></span>
                                        <span class="text-xl text-emerald-700 group-open:rotate-45" aria-hidden="true">+</span>
                                    </summary>
                                    <div class="ml-12 mt-4 space-y-4 text-sm leading-6 text-slate-700">
                                        @if ($day->description)<p>{{ $day->description }}</p>@endif
                                        @if ($activities)
                                            <div><h3 class="font-bold text-slate-900">Activities</h3><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($activities as $activity)<li>{{ $activity }}</li>@endforeach</ul></div>
                                        @endif
                                        @if ($day->meals || $day->overnight_location)
                                            <dl class="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">
                                                @if ($day->meals)<div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Meals</dt><dd class="mt-1">{{ $day->meals }}</dd></div>@endif
                                                @if ($day->overnight_location)<div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Overnight</dt><dd class="mt-1">{{ $day->overnight_location }}</dd></div>@endif
                                            </dl>
                                        @endif
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($inclusions->isNotEmpty() || $exclusions->isNotEmpty())
                    <section class="grid gap-5 md:grid-cols-2" aria-label="Tour inclusions and exclusions">
                        <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6">
                            <h2 class="text-lg font-black text-emerald-950">What is included</h2>
                            @if ($inclusions->isEmpty())<p class="mt-3 text-sm text-emerald-800">No inclusions have been listed.</p>@else
                                <ul class="mt-4 space-y-3 text-sm text-emerald-950">@foreach ($inclusions as $item)<li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-brand-700" /><span>{{ $item->content ?? $item->label }}</span></li>@endforeach</ul>
                            @endif
                        </div>
                        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
                            <h2 class="text-lg font-black text-amber-950">Not included</h2>
                            @if ($exclusions->isEmpty())<p class="mt-3 text-sm text-amber-800">No exclusions have been listed.</p>@else
                                <ul class="mt-4 space-y-3 text-sm text-amber-950">@foreach ($exclusions as $item)<li class="flex gap-2"><span class="font-black text-amber-700" aria-hidden="true">–</span><span>{{ $item->content ?? $item->label }}</span></li>@endforeach</ul>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($ratings?->hasReviews())
                    <section aria-labelledby="reviews-heading">
                        <h2 id="reviews-heading" class="text-2xl font-black text-emerald-950">Traveller reviews</h2>

                        <div class="mt-5 grid gap-6 rounded-3xl border border-slate-200 bg-white p-6 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-center">
                            <div class="text-center sm:text-left">
                                <p class="text-4xl font-black text-emerald-950">{{ number_format($ratings->average(), 1) }}</p>
                                <x-star-rating :rating="$ratings->roundedStars()" :label="$ratings->formattedAverage()" />
                                <p class="mt-1 text-xs text-slate-500">{{ $ratings->reviews_count }} {{ str('review')->plural($ratings->reviews_count) }}</p>
                            </div>
                            <dl class="space-y-1.5">
                                @foreach ([5, 4, 3, 2, 1] as $star)
                                    <div class="flex items-center gap-3 text-xs">
                                        <dt class="w-12 shrink-0 text-slate-600">{{ $star }} star</dt>
                                        <dd class="flex flex-1 items-center gap-3">
                                            <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                                <span class="block h-full rounded-full bg-amber-400" style="width: {{ $ratings->percentageFor($star) }}%"></span>
                                            </span>
                                            <span class="w-8 shrink-0 text-right tabular-nums text-slate-500">{{ $ratings->countFor($star) }}</span>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        <div class="mt-5 space-y-4">
                            @foreach ($reviews as $review)
                                <article class="rounded-3xl border border-slate-200 bg-white p-6">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <x-star-rating :rating="$review->rating" />
                                        @if ($review->published_at)
                                            <time datetime="{{ $review->published_at->toDateString() }}" class="text-xs text-slate-500">{{ $review->published_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 text-lg font-black text-slate-950">{{ $review->title }}</h3>
                                    <p class="mt-1 text-xs font-semibold text-slate-500">{{ $review->authorName() }}</p>
                                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $review->body }}</p>
                                    @if ($review->hasReply())
                                        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Reply from PISFA</p>
                                            <p class="mt-1 text-sm text-slate-800">{{ $review->reply_body }}</p>
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside class="lg:sticky lg:top-24" aria-labelledby="departures-heading">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-lg sm:p-6">
                    <h2 id="departures-heading" class="text-xl font-black text-emerald-950">Scheduled departures</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Choose an open departure. Capacity is checked again when you submit.</p>

                    @if ($departures->isEmpty())
                        <div class="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-950">
                            <p class="font-bold">No departures are scheduled yet.</p>
                            <p class="mt-1 leading-6">Ask the PISFA team about a private or custom date.</p>
                        </div>
                        <a href="{{ route('request-quotation', ['service' => 'tours-safaris']) }}" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-4 py-3 text-sm font-bold text-white hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">Request a custom date</a>
                    @else
                        <div class="mt-5 space-y-4">
                            @foreach ($departures as $departure)
                                @php
                                    $statusValue = $departure->status?->value ?? $departure->status;
                                    $reserved = (int) ($departure->reserved_seats ?? 0);
                                    $remaining = max(0, (int) $departure->capacity - $reserved);
                                    $cutoffPassed = $departure->cancellation_cutoff_at?->isPast() ?? true;
                                    $bookable = $statusValue === 'scheduled' && ! $cutoffPassed && $remaining >= (int) $package->min_travelers;
                                    $price = $departure->price_override_minor ?? $package->base_price_minor;
                                    $currency = $departure->currency ?? $package->currency;
                                @endphp
                                <article class="rounded-2xl border border-slate-200 p-4" aria-labelledby="departure-{{ $departure->id }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 id="departure-{{ $departure->id }}" class="font-black text-slate-950"><time datetime="{{ $departure->starts_at->toDateString() }}">{{ $departure->starts_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></h3>
                                            <p class="mt-1 text-xs text-slate-500">to <time datetime="{{ $departure->ends_at->toDateString() }}">{{ $departure->ends_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}</time></p>
                                        </div>
                                        <span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-50 text-emerald-800' => $bookable, 'bg-amber-50 text-amber-800' => ! $bookable])>{{ $bookable ? 'Open' : (($remaining === 0) ? 'Sold out' : ($cutoffPassed ? 'Booking cutoff passed' : (($remaining < (int) $package->min_travelers) ? 'Insufficient places' : ($departure->status?->label() ?? str($statusValue)->headline())))) }}</span>
                                    </div>
                                    <p class="mt-3 font-black text-amber-700">{{ \App\Support\Money::format((int) $price, $currency) }} <span class="text-xs font-medium text-slate-500">per traveler</span></p>
                                    <p class="mt-2 text-xs text-slate-600">{{ $remaining }} of {{ $departure->capacity }} {{ str('place')->plural($departure->capacity) }} available</p>
                                    @if ($departure->meeting_point)<p class="mt-2 text-xs leading-5 text-slate-600"><span class="font-bold">Meeting point:</span> {{ $departure->meeting_point }}</p>@endif
                                    @if ($bookable)
                                        <a href="{{ route('tour-bookings.create', ['tourPackage' => $package, 'departure' => $departure]) }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">Book this departure</a>
                                    @else
                                        <p class="mt-3 text-xs font-medium text-slate-500">Online booking is unavailable for this departure.</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-6 border-t border-slate-200 pt-5 text-xs leading-5 text-slate-500">
                        <p>No payment is collected while you browse or submit a booking request.</p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
