@extends('layouts.public')

@php
    $title = $listing->title;
    $description = Str::limit(strip_tags($listing->description), 155);
    $cover = $listing->coverUrl();
    $gallery = $listing->media;
    $canEnquire = $listing->acceptsEnquiries();
    $user = auth()->user();
    $specs = array_filter([
        'Year' => $listing->year,
        'Make' => $listing->make,
        'Model' => $listing->model,
        'Body' => $listing->body_type,
        'Transmission' => $listing->transmission,
        'Fuel' => $listing->fuel_type,
        'Colour' => $listing->colour,
        'Seats' => $listing->seating_capacity,
        'Mileage' => $listing->formattedMileage(),
        'Condition' => $listing->condition,
    ], static fn ($value) => filled($value));
@endphp

@section('content')
    <nav aria-label="Breadcrumb" class="mx-auto max-w-7xl px-4 pt-8 sm:px-6 lg:px-8">
        <a href="{{ route('showroom.index') }}" class="text-sm font-bold text-emerald-800 hover:underline">&larr; Back to the showroom</a>
    </nav>

    @if (session('success'))
        <div class="mx-auto mt-6 max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-900" role="status">
                {{ session('success') }}
            </div>
        </div>
    @endif

    <article class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="grid gap-10 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-100">
                    @if ($cover)
                        <img src="{{ $cover }}" alt="{{ $listing->title }}" class="aspect-[16/10] w-full object-cover">
                    @else
                        <div class="flex aspect-[16/10] w-full items-center justify-center text-sm font-semibold text-slate-400">
                            Photographs coming soon
                        </div>
                    @endif
                </div>

                @if ($gallery->count() > 1)
                    <div class="mt-4 grid grid-cols-4 gap-3">
                        @foreach ($gallery->slice(1)->take(8) as $shot)
                            <img src="{{ $shot->url() }}" alt="{{ $listing->title }}" loading="lazy"
                                 class="aspect-square w-full rounded-2xl border border-slate-200 object-cover">
                        @endforeach
                    </div>
                @endif

                <h1 class="mt-8 text-3xl font-black text-emerald-950 sm:text-4xl">{{ $listing->title }}</h1>
                <p class="mt-2 text-sm font-semibold text-slate-500">Reference {{ $listing->reference }}</p>

                <div class="prose prose-slate mt-6 max-w-none">
                    @foreach (preg_split('/\R{2,}/', $listing->description) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>

                <h2 class="mt-10 text-xl font-black text-emerald-950">Specification</h2>
                <dl class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    @foreach ($specs as $label => $value)
                        <div class="flex justify-between border-b border-slate-200 py-2">
                            <dt class="text-sm font-semibold text-slate-600">{{ $label }}</dt>
                            <dd class="text-sm font-bold text-slate-900">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <aside class="lg:col-span-1">
                <div class="sticky top-6 space-y-6">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-emerald-100 text-emerald-900' => $listing->status === \App\Enums\ListingStatus::Available,
                            'bg-amber-100 text-amber-900' => $listing->status === \App\Enums\ListingStatus::Reserved,
                            'bg-sky-100 text-sky-900' => $listing->status === \App\Enums\ListingStatus::Sold,
                        ])>{{ $listing->status->label() }}</span>

                        <p class="mt-4 text-3xl font-black text-emerald-800">{{ $listing->formattedAskingPrice() }}</p>

                        @if ($listing->is_negotiable && $canEnquire)
                            <p class="mt-1 text-sm text-slate-600">Negotiable — make us an offer below.</p>
                        @endif

                        @if ($listing->status === \App\Enums\ListingStatus::Reserved)
                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                A deposit is being held on this vehicle. You may still register interest in case it
                                becomes available again.
                            </p>
                        @elseif ($listing->status === \App\Enums\ListingStatus::Sold)
                            <p class="mt-3 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
                                This vehicle has been sold. Have a look at
                                <a href="{{ route('showroom.index') }}" class="font-bold underline">what is currently available</a>.
                            </p>
                        @endif
                    </div>

                    @if ($canEnquire)
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-emerald-950">Enquire about this vehicle</h2>
                            <p class="mt-1 text-sm text-slate-600">
                                We reply by email, usually the same working day.
                            </p>

                            @if ($errors->any())
                                <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                                    <p class="font-bold">Please check the form.</p>
                                    <ul class="mt-2 list-disc space-y-1 pl-5">
                                        @foreach ($errors->all() as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <form method="POST" action="{{ route('showroom.enquire', $listing->slug) }}" class="mt-5 space-y-4">
                                @csrf
                                {{-- Replay protection: a refreshed submission reuses this key and
                                     returns the enquiry already recorded rather than filing a second. --}}
                                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                                @auth
                                    <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                                        Enquiring as <span class="font-bold">{{ $user->name }}</span> ({{ $user->email }}).
                                    </p>
                                @else
                                    <div>
                                        <label for="enquiry-name" class="block text-sm font-semibold">Your name</label>
                                        <input id="enquiry-name" name="contact_name" type="text" required maxlength="180"
                                               value="{{ old('contact_name') }}" autocomplete="name"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="enquiry-email" class="block text-sm font-semibold">Email</label>
                                        <input id="enquiry-email" name="contact_email" type="email" required maxlength="254"
                                               value="{{ old('contact_email') }}" autocomplete="email"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="enquiry-phone" class="block text-sm font-semibold">Phone</label>
                                        <input id="enquiry-phone" name="contact_phone" type="tel" required maxlength="40"
                                               value="{{ old('contact_phone') }}" autocomplete="tel"
                                               placeholder="+256700000000"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                @endauth

                                <div>
                                    <label for="enquiry-message" class="block text-sm font-semibold">Message <span class="font-normal text-slate-500">(optional)</span></label>
                                    <textarea id="enquiry-message" name="message" rows="4" maxlength="2000"
                                              placeholder="When could I come and see it?"
                                              class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('message') }}</textarea>
                                </div>

                                @if ($listing->is_negotiable)
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="col-span-2">
                                            <label for="enquiry-offer" class="block text-sm font-semibold">Your offer <span class="font-normal text-slate-500">(optional)</span></label>
                                            <input id="enquiry-offer" name="offer" type="text" inputmode="numeric" maxlength="24"
                                                   value="{{ old('offer') }}"
                                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        </div>
                                        <div>
                                            <label for="enquiry-offer-currency" class="block text-sm font-semibold">Currency</label>
                                            <select id="enquiry-offer-currency" name="offer_currency"
                                                    class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                                @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                                    <option value="{{ $code }}" @selected(old('offer_currency', $listing->currency) === $code)>{{ $code }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                @endif

                                <button type="submit"
                                        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
                                    Send enquiry
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </aside>
        </div>

        @if ($similar->isNotEmpty())
            <section class="mt-16" aria-labelledby="similar-heading">
                <h2 id="similar-heading" class="text-2xl font-black text-emerald-950">Other {{ $listing->make }}s available</h2>
                <div class="mt-6 grid gap-6 md:grid-cols-3">
                    @foreach ($similar as $item)
                        @include('showroom.partials.card', ['listing' => $item])
                    @endforeach
                </div>
            </section>
        @endif
    </article>
@endsection
