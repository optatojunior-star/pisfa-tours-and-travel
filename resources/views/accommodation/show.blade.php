@extends('layouts.public')

@php
    $title = $property->name;
    $description = $property->summary;
    $cover = $property->coverUrl();
    $user = auth()->user();
    $datesChosen = $checkIn !== null && $checkOut !== null;
@endphp

@section('content')
    <nav aria-label="Breadcrumb" class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
        <a href="{{ route('accommodation.index') }}" class="text-sm font-bold text-emerald-800 hover:underline">&larr; All places to stay</a>
    </nav>

    @if (session('success'))
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-900" role="status">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-900" role="alert">
                <p class="font-bold">We could not take that booking.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <article class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-100">
            @if ($cover)
                <img src="{{ $cover }}" alt="{{ $property->name }}" class="aspect-[21/9] w-full object-cover">
            @else
                <div class="flex aspect-[21/9] w-full items-center justify-center text-sm font-semibold text-slate-400">
                    Photographs coming soon
                </div>
            @endif
        </div>

        <div class="mt-8 grid gap-10 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $property->property_type->label() }}</p>
                <h1 class="mt-1 text-3xl font-black text-emerald-950 sm:text-4xl">{{ $property->name }}</h1>
                <p class="mt-2 text-sm font-semibold text-slate-600">{{ $property->locationLabel() }}</p>

                @if ($reviewSummary?->hasReviews())
                    <p class="mt-3 text-sm font-semibold text-amber-700">
                        {{ $reviewSummary->formattedAverage() }}
                        <span class="font-normal text-slate-500">from {{ $reviewSummary->reviews_count }} {{ Str::plural('review', $reviewSummary->reviews_count) }}</span>
                    </p>
                @endif

                <div class="prose prose-slate mt-6 max-w-none">
                    @foreach (preg_split('/\R{2,}/', $property->description) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>

                <dl class="mt-8 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Check in</dt>
                        <dd class="text-sm font-bold text-slate-900">From {{ \Illuminate\Support\Str::substr($property->getRawOriginal('check_in_from'), 0, 5) }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Check out</dt>
                        <dd class="text-sm font-bold text-slate-900">By {{ \Illuminate\Support\Str::substr($property->getRawOriginal('check_out_by'), 0, 5) }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2 sm:col-span-2">
                        <dt class="text-sm font-semibold text-slate-600">Free cancellation</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            @if ($property->cancellation_cutoff_hours === 0)
                                Not offered — ask us before booking
                            @else
                                Up to {{ $property->cancellation_cutoff_hours }} hours before arrival
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($property->directions)
                    <section class="mt-8" aria-labelledby="directions-heading">
                        <h2 id="directions-heading" class="text-xl font-black text-emerald-950">Getting there</h2>
                        <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $property->directions }}</p>
                    </section>
                @endif

                @if ($reviews->isNotEmpty())
                    <section class="mt-10" aria-labelledby="reviews-heading">
                        <h2 id="reviews-heading" class="text-xl font-black text-emerald-950">What guests said</h2>
                        <ul class="mt-4 space-y-4">
                            @foreach ($reviews as $review)
                                <li class="rounded-2xl border border-slate-200 bg-white p-5">
                                    <p class="font-bold text-slate-900">{{ $review->title }}</p>
                                    <p class="mt-1 text-sm font-semibold text-amber-700">{{ $review->rating }} out of 5</p>
                                    <p class="mt-2 text-sm text-slate-700">{{ $review->body }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <aside class="lg:col-span-1">
                <div class="sticky top-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-black text-emerald-950">Check availability</h2>

                    <form method="GET" action="{{ route('accommodation.show', $property->slug) }}" class="mt-4 space-y-3">
                        <div>
                            <label for="check-in" class="block text-sm font-semibold">Arriving</label>
                            <input id="check-in" name="check_in_date" type="date" required
                                   value="{{ $checkIn?->toDateString() }}"
                                   min="{{ now(config('pisfa.business_timezone', 'Africa/Kampala'))->toDateString() }}"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div>
                            <label for="check-out" class="block text-sm font-semibold">Leaving</label>
                            <input id="check-out" name="check_out_date" type="date" required
                                   value="{{ $checkOut?->toDateString() }}"
                                   min="{{ now(config('pisfa.business_timezone', 'Africa/Kampala'))->addDay()->toDateString() }}"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <input type="hidden" name="currency" value="{{ $currency }}">
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-700 px-5 text-sm font-bold text-emerald-800 hover:bg-emerald-50">
                            Show what is free
                        </button>
                    </form>

                    @if (! $datesChosen)
                        <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                            Choose your dates and we will show exactly what is free — and what it costs — before you
                            commit to anything.
                        </p>
                    @else
                        <p class="mt-4 text-sm font-semibold text-slate-700">
                            {{ $nights }} {{ Str::plural('night', $nights) }},
                            {{ $checkIn->format('j M') }} to {{ $checkOut->format('j M Y') }}
                        </p>
                    @endif
                </div>
            </aside>
        </div>

        <section class="mt-12" aria-labelledby="rooms-heading">
            <h2 id="rooms-heading" class="text-2xl font-black text-emerald-950">Rooms</h2>

            @if ($property->roomTypes->isEmpty())
                <p class="mt-4 text-sm text-slate-600">No rooms are listed yet. Please ask us.</p>
            @else
                <div class="mt-6 space-y-6">
                    @foreach ($property->roomTypes as $roomType)
                        @php
                            $state = $availability[$roomType->getKey()] ?? null;
                            $roomsLeft = $state['rooms'] ?? null;
                            $rate = $state['rate'] ?? null;
                            $fromPrice = $roomType->fromPrice($currency);
                        @endphp

                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-black text-emerald-950">{{ $roomType->name }}</h3>
                                    <p class="mt-1 text-sm text-slate-600">
                                        Sleeps {{ $roomType->occupancyLabel() }}
                                        @if ($roomType->bed_configuration)
                                            · {{ $roomType->bed_configuration }}
                                        @endif
                                    </p>
                                    @if ($roomType->description)
                                        <p class="mt-2 text-sm text-slate-700">{{ $roomType->description }}</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    @if ($rate)
                                        <p class="text-xl font-black text-emerald-800">{{ $rate->formattedNightlyRate() }}</p>
                                        <p class="text-xs text-slate-500">per room, per night</p>
                                        @if ($nights)
                                            <p class="mt-1 text-sm font-bold text-slate-900">
                                                {{ \App\Support\Money::format($rate->nightly_rate_minor * $nights, $rate->currency) }}
                                                <span class="font-normal text-slate-500">for {{ $nights }} {{ Str::plural('night', $nights) }}</span>
                                            </p>
                                        @endif
                                    @elseif ($fromPrice)
                                        <p class="text-xl font-black text-emerald-800">From {{ $fromPrice }}</p>
                                        <p class="text-xs text-slate-500">per room, per night</p>
                                    @else
                                        <p class="text-sm font-semibold text-slate-500">Priced on request</p>
                                    @endif
                                </div>
                            </div>

                            @if (! $datesChosen)
                                <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                                    Choose your dates above to see whether this room is free.
                                </p>
                            @elseif ($rate === null)
                                <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                    We have no set price for those dates in {{ $currency }} — a stay that crosses two
                                    seasons is quoted by hand.
                                    <a href="{{ route('request-quotation') }}" class="font-bold underline">Ask us for a price</a>.
                                </p>
                            @elseif ($roomsLeft === 0)
                                <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                    Fully booked for those dates.
                                </p>
                            @elseif ($nights < $rate->minimum_nights)
                                <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                    This room has a minimum stay of {{ $rate->minimum_nights }} {{ Str::plural('night', $rate->minimum_nights) }}.
                                </p>
                            @else
                                <p class="mt-4 text-sm font-semibold text-emerald-800">
                                    {{ $roomsLeft }} {{ Str::plural('room', $roomsLeft) }} left for those dates.
                                </p>

                                @auth
                                    <form method="POST" action="{{ route('accommodation.book', $property->slug) }}" class="mt-4 space-y-4">
                                        @csrf
                                        <input type="hidden" name="property_room_type_id" value="{{ $roomType->getKey() }}">
                                        <input type="hidden" name="check_in_date" value="{{ $checkIn->toDateString() }}">
                                        <input type="hidden" name="check_out_date" value="{{ $checkOut->toDateString() }}">
                                        <input type="hidden" name="currency" value="{{ $currency }}">
                                        {{-- Replay protection: a refreshed submit reuses this key and returns
                                             the booking already made rather than taking a second room. --}}
                                        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label for="rooms-{{ $roomType->getKey() }}" class="block text-sm font-semibold">Rooms</label>
                                                <input id="rooms-{{ $roomType->getKey() }}" name="rooms" type="number" required
                                                       min="1" max="{{ $roomsLeft }}" value="{{ old('rooms', 1) }}"
                                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                            </div>
                                            <div>
                                                <label for="adults-{{ $roomType->getKey() }}" class="block text-sm font-semibold">Adults</label>
                                                <input id="adults-{{ $roomType->getKey() }}" name="adults" type="number" required
                                                       min="1" max="60" value="{{ old('adults', 2) }}"
                                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                            </div>
                                            <div>
                                                <label for="children-{{ $roomType->getKey() }}" class="block text-sm font-semibold">Children</label>
                                                <input id="children-{{ $roomType->getKey() }}" name="children" type="number"
                                                       min="0" max="60" value="{{ old('children', 0) }}"
                                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                            </div>
                                        </div>

                                        <div>
                                            <label for="phone-{{ $roomType->getKey() }}" class="block text-sm font-semibold">Phone</label>
                                            <input id="phone-{{ $roomType->getKey() }}" name="contact_phone" type="tel" required
                                                   maxlength="40" value="{{ old('contact_phone', $user->phone) }}"
                                                   placeholder="+256700000000"
                                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        </div>

                                        <div>
                                            <label for="requests-{{ $roomType->getKey() }}" class="block text-sm font-semibold">
                                                Anything we should know? <span class="font-normal text-slate-500">(optional)</span>
                                            </label>
                                            <textarea id="requests-{{ $roomType->getKey() }}" name="special_requests" rows="2" maxlength="5000"
                                                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('special_requests') }}</textarea>
                                        </div>

                                        <label class="flex items-start gap-3">
                                            <input type="checkbox" name="acknowledge_request" value="1" required
                                                   class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                            <span class="text-sm text-slate-700">
                                                I understand this is a request. We hold the rooms while PISFA confirms
                                                with the property, and nothing is charged until it is confirmed.
                                            </span>
                                        </label>

                                        <button type="submit"
                                                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
                                            Request this room
                                        </button>
                                    </form>
                                @else
                                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                                        <a href="{{ route('login') }}" class="font-bold text-emerald-800 underline">Sign in</a>
                                        or
                                        <a href="{{ route('register') }}" class="font-bold text-emerald-800 underline">create an account</a>
                                        to book. We need an account so you can see and change your stay afterwards.
                                    </p>
                                @endauth
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </article>
@endsection
