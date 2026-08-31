@extends('layouts.public')

@php
    $unitPrice = (int) ($departure->price_override_minor ?? $package->base_price_minor);
    $currency = $departure->currency ?? $package->currency;
    $maximumTravelers = max(1, (int) $package->max_travelers);
    $initialCount = min($maximumTravelers, max((int) $package->min_travelers, (int) old('traveler_count', $package->min_travelers)));
    $reserved = (int) ($departure->reserved_seats ?? 0);
    $remaining = max(0, (int) $departure->capacity - $reserved);
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
        <nav aria-label="Breadcrumb" class="text-sm text-slate-600">
            <ol class="flex flex-wrap items-center gap-2">
                <li><a href="{{ route('tours.index') }}" class="font-semibold text-emerald-800 hover:text-emerald-950">Tours</a></li>
                <li aria-hidden="true">/</li>
                <li><a href="{{ route('tours.show', ['tourPackage' => $package]) }}" class="font-semibold text-emerald-800 hover:text-emerald-950">{{ $package->name }}</a></li>
                <li aria-hidden="true">/</li>
                <li aria-current="page">Booking request</li>
            </ol>
        </nav>

        <div class="mt-7">
            <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">Secure booking request</p>
            <h1 class="mt-2 text-3xl font-black tracking-tight text-emerald-950 sm:text-4xl">Plan your places on {{ $package->name }}</h1>
            <p class="mt-3 max-w-3xl leading-7 text-slate-600">Review the departure, add traveler information, and submit your request. Capacity and pricing are checked once more when you submit.</p>
        </div>

        @if ($errors->any())
            <div id="booking-errors" class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1">
                <p class="font-bold">Your booking request was not saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('tour-bookings.store', ['tourPackage' => $package]) }}" class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start" x-data="{ travelerCount: {{ $initialCount }} }">
            @csrf
            <input type="hidden" name="departure_id" value="{{ $departure->id }}">
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

            <div class="space-y-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="contact-heading">
                    <h2 id="contact-heading" class="text-xl font-black text-emerald-950">Lead contact</h2>
                    <p class="mt-1 text-sm text-slate-600">We will use these details for this booking only.</p>
                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="contact-name" class="block text-sm font-semibold text-slate-800">Full name</label>
                            <input id="contact-name" type="text" readonly value="{{ auth()->user()->name }}" class="mt-1 block w-full rounded-xl border-slate-300 bg-slate-50 text-slate-600 shadow-sm">
                        </div>
                        <div>
                            <label for="contact-email" class="block text-sm font-semibold text-slate-800">Email</label>
                            <input id="contact-email" type="email" readonly value="{{ auth()->user()->email }}" class="mt-1 block w-full rounded-xl border-slate-300 bg-slate-50 text-slate-600 shadow-sm">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="contact-phone" class="block text-sm font-semibold text-slate-800">Telephone number</label>
                            <input id="contact-phone" name="contact_phone" type="tel" required maxlength="40" autocomplete="tel" value="{{ old('contact_phone', auth()->user()->phone) }}" placeholder="+256 …" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600" aria-describedby="contact-phone-help contact-phone-error">
                            <p id="contact-phone-help" class="mt-2 text-xs text-slate-500">Include the country code if you are outside Uganda.</p>
                            <x-input-error id="contact-phone-error" :messages="$errors->get('contact_phone')" class="mt-2" />
                        </div>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="travelers-heading">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2 id="travelers-heading" class="text-xl font-black text-emerald-950">Travelers</h2>
                            <p class="mt-1 text-sm text-slate-600">Add between {{ $package->min_travelers }} and {{ min($maximumTravelers, $remaining) }} travelers for this departure.</p>
                        </div>
                        <div class="w-full sm:w-36">
                            <label for="traveler-count" class="block text-sm font-semibold text-slate-800">Number traveling</label>
                            <input id="traveler-count" name="traveler_count" type="number" required min="{{ $package->min_travelers }}" max="{{ min($maximumTravelers, $remaining) }}" x-model.number="travelerCount" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                    </div>

                    <div class="mt-6 space-y-5">
                        @for ($index = 0; $index < $maximumTravelers; $index++)
                            <fieldset x-show="travelerCount > {{ $index }}" x-cloak x-bind:disabled="travelerCount <= {{ $index }}" class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5">
                                <legend class="px-2 font-bold text-slate-900">Traveler {{ $index + 1 }} @if ($index === 0)<span class="text-xs font-medium text-emerald-700">Lead traveler</span>@endif</legend>
                                <input type="hidden" name="travelers[{{ $index }}][is_lead]" value="{{ $index === 0 ? 1 : 0 }}">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="traveler-{{ $index }}-name" class="block text-sm font-semibold text-slate-800">Full name</label>
                                        <input id="traveler-{{ $index }}-name" name="travelers[{{ $index }}][full_name]" type="text" maxlength="120" value="{{ old("travelers.$index.full_name", $index === 0 ? auth()->user()->name : '') }}" @required($index < $initialCount) class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        <x-input-error :messages="$errors->get("travelers.$index.full_name")" class="mt-2" />
                                    </div>
                                    <div>
                                        <label for="traveler-{{ $index }}-type" class="block text-sm font-semibold text-slate-800">Traveler type</label>
                                        <select id="traveler-{{ $index }}-type" name="travelers[{{ $index }}][traveler_type]" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            <option value="adult" @selected(old("travelers.$index.traveler_type", 'adult') === 'adult')>Adult</option>
                                            <option value="child" @selected(old("travelers.$index.traveler_type") === 'child')>Child</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="traveler-{{ $index }}-dob" class="block text-sm font-semibold text-slate-800">Date of birth <span class="font-normal text-slate-500">(optional)</span></label>
                                        <input id="traveler-{{ $index }}-dob" name="travelers[{{ $index }}][date_of_birth]" type="date" max="{{ now(config('pisfa.business_timezone'))->toDateString() }}" value="{{ old("travelers.$index.date_of_birth") }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="traveler-{{ $index }}-nationality" class="block text-sm font-semibold text-slate-800">Nationality <span class="font-normal text-slate-500">(optional)</span></label>
                                        <input id="traveler-{{ $index }}-nationality" name="travelers[{{ $index }}][nationality]" type="text" maxlength="100" autocomplete="country-name" value="{{ old("travelers.$index.nationality") }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="traveler-{{ $index }}-diet" class="block text-sm font-semibold text-slate-800">Dietary notes <span class="font-normal text-slate-500">(optional)</span></label>
                                        <textarea id="traveler-{{ $index }}-diet" name="travelers[{{ $index }}][dietary_notes]" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old("travelers.$index.dietary_notes") }}</textarea>
                                    </div>
                                    <div>
                                        <label for="traveler-{{ $index }}-access" class="block text-sm font-semibold text-slate-800">Accessibility notes <span class="font-normal text-slate-500">(optional)</span></label>
                                        <textarea id="traveler-{{ $index }}-access" name="travelers[{{ $index }}][accessibility_notes]" rows="2" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old("travelers.$index.accessibility_notes") }}</textarea>
                                    </div>
                                </div>
                            </fieldset>
                        @endfor
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="requests-heading">
                    <h2 id="requests-heading" class="text-xl font-black text-emerald-950">Requests and acknowledgement</h2>
                    <div class="mt-5">
                        <label for="special-requests" class="block text-sm font-semibold text-slate-800">Special requests <span class="font-normal text-slate-500">(optional)</span></label>
                        <textarea id="special-requests" name="special_requests" rows="4" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600" placeholder="Tell us anything that will help the team plan safely and comfortably.">{{ old('special_requests') }}</textarea>
                        <x-input-error :messages="$errors->get('special_requests')" class="mt-2" />
                    </div>
                    <label class="mt-5 flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                        <input name="accept_terms" type="checkbox" value="1" required @checked(old('accept_terms')) class="mt-1 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600">
                        <span>I have reviewed the dates, traveler details and PISFA <a href="{{ route('terms') }}" target="_blank" class="font-bold underline">terms</a>. I understand this request is not confirmed until PISFA approves it.</span>
                    </label>
                    <x-input-error :messages="$errors->get('accept_terms')" class="mt-2" />
                </section>
            </div>

            <aside class="space-y-5 lg:sticky lg:top-24" aria-labelledby="booking-summary-heading">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-lg sm:p-6">
                    <h2 id="booking-summary-heading" class="text-xl font-black text-emerald-950">Booking summary</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div><dt class="text-slate-500">Tour</dt><dd class="mt-1 font-bold text-slate-900">{{ $package->name }}</dd></div>
                        <div><dt class="text-slate-500">Destination</dt><dd class="mt-1 font-bold text-slate-900">{{ $package->destination }}</dd></div>
                        <div><dt class="text-slate-500">Departure</dt><dd class="mt-1 font-bold text-slate-900"><time datetime="{{ $departure->starts_at->toIso8601String() }}">{{ $departure->starts_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                        <div><dt class="text-slate-500">Returns</dt><dd class="mt-1 font-bold text-slate-900"><time datetime="{{ $departure->ends_at->toIso8601String() }}">{{ $departure->ends_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                        @if ($departure->meeting_point)<div><dt class="text-slate-500">Meeting point</dt><dd class="mt-1 font-bold text-slate-900">{{ $departure->meeting_point }}</dd></div>@endif
                    </dl>

                    <div class="mt-6 rounded-2xl bg-slate-50 p-4">
                        <div class="flex justify-between gap-4 text-sm"><span class="text-slate-600">Per traveler</span><span class="font-bold text-slate-900">{{ \App\Support\Money::format($unitPrice, $currency) }}</span></div>
                        <div class="mt-3 flex justify-between gap-4 border-t border-slate-200 pt-3"><span class="font-bold text-slate-900">Estimated total</span><span class="text-lg font-black text-amber-700" x-text="new Intl.NumberFormat('en-UG', { style: 'currency', currency: '{{ $currency }}', maximumFractionDigits: '{{ $currency }}' === 'UGX' ? 0 : 2 }).format(({{ $unitPrice }} / ('{{ $currency }}' === 'UGX' ? 1 : 100)) * travelerCount)">{{ \App\Support\Money::format($unitPrice * $initialCount, $currency) }}</span></div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">
                        <p class="font-bold">No payment is taken on this page.</p>
                        <p class="mt-1">After reviewing availability, PISFA will confirm the request and send separate payment instructions.</p>
                    </div>

                    <button type="submit" class="mt-5 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 font-black text-white shadow-sm hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2">Submit booking request</button>
                    <p class="mt-3 text-center text-xs leading-5 text-slate-500">Submitting twice with the same page token will not create a duplicate booking.</p>
                </section>
            </aside>
        </form>
    </div>
@endsection

@if ($errors->any())
    @push('scripts')
        <script>document.getElementById('booking-errors')?.focus();</script>
    @endpush
@endif
