@extends('layouts.public')

@php
    use App\Enums\AirportTransferType;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currencies = config('airport_transfers.currencies', ['UGX', 'USD']);
    $selectedType = $filters['transfer_type'] ?? '';
    $selectedCurrency = $filters['currency'] ?? ($currencies[0] ?? 'UGX');
    $earliestValue = $earliestStart->format('Y-m-d\TH:i');
    $user = auth()->user();
    $isCustomer = $user?->hasRole(\App\Enums\UserRole::Customer) ?? false;
@endphp

@section('content')
<section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 sm:py-20 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-300">Airport transfers</p>
        <h1 class="mt-3 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl">Entebbe and upcountry airport transfers</h1>
        <p class="mt-5 max-w-2xl text-lg leading-8 text-emerald-100">Tell us the flight and the address. We price the route from the published rate table, then confirm a vehicle and driver. No payment is collected online.</p>
    </div>
</section>

<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <p class="font-bold">Check the transfer details.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    {{--
        The published price list.

        Pricing was accurate and completely invisible: you had to choose a
        direction, an airport, a place, a currency, a flight time, a party size
        and a luggage count before the site would tell you a single figure. That
        is a form for somebody who has already decided to book with PISFA, not
        for somebody deciding whether to. The table below answers "what does
        Entebbe to Kampala cost, and in what vehicle" straight away, and each
        row starts the booking with that route already chosen.
    --}}
    @if ($priceList->isNotEmpty())
        <section class="mb-10 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="transfer-prices-heading">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Our prices</p>
                    <h2 id="transfer-prices-heading" class="mt-1 text-xl font-black text-emerald-950">Airport pickup and drop-off, per transfer</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        One price per vehicle for the whole transfer, not per person. Pickups include the driver waiting
                        with a name board, and flight delays are watched rather than charged for.
                    </p>
                </div>
                <form method="GET" action="{{ route('airport-transfers.index') }}" class="flex items-end gap-2">
                    <div>
                        <label for="price-currency" class="block text-xs font-bold uppercase tracking-wide text-slate-500">Currency</label>
                        <select id="price-currency" name="currency" onchange="this.form.submit()"
                                class="mt-1 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript><button class="min-h-11 rounded-xl bg-emerald-800 px-4 text-sm font-bold text-white">Show</button></noscript>
                </form>
            </div>

            <div class="mt-6 grid gap-5 lg:grid-cols-2">
                @foreach ($priceList as $group)
                    @php($first = $group->first())
                    <article class="rounded-2xl border border-slate-200 bg-stone-50/70 p-5">
                        <h3 class="text-base font-black text-emerald-950">
                            {{ $first->airport->code }} &harr; {{ $first->location->name }}
                        </h3>
                        <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $first->airport->name }} · {{ $first->location->region }}
                        </p>

                        <div class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <caption class="sr-only">
                                    Transfer prices between {{ $first->airport->name }} and {{ $first->location->name }}
                                </caption>
                                <thead>
                                    <tr class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                                        <th scope="col" class="py-2 pr-3">Vehicle</th>
                                        <th scope="col" class="py-2 pr-3">Takes</th>
                                        <th scope="col" class="py-2 pr-3">Direction</th>
                                        <th scope="col" class="py-2 text-right">Price</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($group as $rate)
                                        <tr>
                                            <td class="py-2.5 pr-3 font-bold text-slate-900">
                                                {{ str($rate->vehicle_type)->replace('_', ' ')->title() }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-slate-600">
                                                {{ $rate->passenger_capacity }}
                                                <span class="sr-only">passengers,</span>
                                                <span aria-hidden="true">pax</span> ·
                                                {{ $rate->luggage_capacity }}<span class="sr-only"> pieces of luggage</span><span aria-hidden="true"> bags</span>
                                            </td>
                                            <td class="py-2.5 pr-3 text-slate-600">{{ $rate->transfer_type->label() }}</td>
                                            <td class="py-2.5 text-right font-black text-emerald-800">
                                                {{ Money::format($rate->amount_minor, $rate->currency) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <a href="{{ route('airport-transfers.index', [
                                'transfer_type' => $first->transfer_type->value,
                                'airport_id' => $first->airport_id,
                                'airport_transfer_location_id' => $first->airport_transfer_location_id,
                                'currency' => $selectedCurrency,
                            ]) }}#transfer-quote-heading"
                           class="mt-4 inline-flex min-h-11 items-center text-sm font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">
                            Book this route
                        </a>
                    </article>
                @endforeach
            </div>

            <p class="mt-5 text-xs leading-5 text-slate-500">
                Prices in {{ $selectedCurrency }}, currently in force, and confirmed again against the flight time when you
                submit a request. A route that is not listed is still possible —
                <a href="{{ route('request-quotation', ['service' => 'airport-transfers']) }}" class="font-bold text-emerald-800 underline">ask for a quotation</a>.
            </p>
        </section>
    @endif

    <div id="transfer-quote-heading" class="scroll-mt-8">
        <x-filter-panel :action="route('airport-transfers.index')" eyebrow="Step 1" heading="Price your transfer"
                        :filters="$filters" submit="Show prices"
                        note="Landing time for a pickup, departure time for a drop-off. Times are interpreted in Africa/Kampala.">
            <x-slot:primary>
                <div>
                    <label for="transfer-type" class="block text-slate-800">Direction</label>
                    <select id="transfer-type" name="transfer_type" class="mt-1 block w-full border-slate-300">
                        <option value="">Select direction</option>
                        @foreach (AirportTransferType::cases() as $case)
                            <option value="{{ $case->value }}" @selected($selectedType === $case->value)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-airport" class="block text-slate-800">Airport</label>
                    <select id="transfer-airport" name="airport_id" class="mt-1 block w-full border-slate-300">
                        <option value="">Select airport</option>
                        @foreach ($airports as $airport)
                            <option value="{{ $airport->id }}" @selected((int) ($filters['airport_id'] ?? 0) === $airport->id)>{{ $airport->code }} — {{ $airport->name }}</option>
                        @endforeach
                    </select>
                </div>
            </x-slot:primary>

            <div>
                <label for="transfer-location" class="block text-slate-800">Service location</label>
                <select id="transfer-location" name="airport_transfer_location_id" class="mt-1 block w-full border-slate-300">
                    <option value="">Select location</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((int) ($filters['airport_transfer_location_id'] ?? 0) === $location->id)>{{ $location->name }} ({{ $location->region }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transfer-currency" class="block text-slate-800">Currency</label>
                <select id="transfer-currency" name="currency" class="mt-1 block w-full border-slate-300">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="flight-scheduled-at" class="block text-slate-800">Flight time</label>
                <input id="flight-scheduled-at" name="flight_scheduled_at" type="datetime-local"
                       value="{{ $filters['flight_scheduled_at'] ?? '' }}" class="mt-1 block w-full border-slate-300">
            </div>
            <div>
                <label for="service-starts-at" class="block text-slate-800">Address pickup <span class="font-normal text-slate-500">(drop-off only)</span></label>
                <input id="service-starts-at" name="service_starts_at" type="datetime-local" min="{{ $earliestValue }}"
                       value="{{ $filters['service_starts_at'] ?? '' }}" class="mt-1 block w-full border-slate-300">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="passenger-count" class="block text-slate-800">Passengers</label>
                    <input id="passenger-count" name="passenger_count" type="number" inputmode="numeric" min="1"
                           max="{{ config('airport_transfers.maximum_passengers', 50) }}"
                           value="{{ $filters['passenger_count'] ?? 1 }}" class="mt-1 block w-full border-slate-300">
                </div>
                <div>
                    <label for="luggage-count" class="block text-slate-800">Luggage</label>
                    <input id="luggage-count" name="luggage_count" type="number" inputmode="numeric" min="0"
                           max="{{ config('airport_transfers.maximum_luggage', 100) }}"
                           value="{{ $filters['luggage_count'] ?? 0 }}" class="mt-1 block w-full border-slate-300">
                </div>
            </div>
        </x-filter-panel>
    </div>

    @if ($transferType !== null && $serviceStartsAt !== null)
        <section class="mt-10" aria-labelledby="transfer-options-heading">
            <h2 id="transfer-options-heading" class="text-xl font-black text-emerald-950">Step 2 — choose a vehicle and confirm details</h2>
            <p class="mt-2 text-sm text-slate-600">Service time {{ $serviceStartsAt->timezone($timezone)->format('j M Y, H:i') }} ({{ $timezone }}). Prices are the published rate for this route and time.</p>

            @if ($options->isEmpty())
                <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h3 class="text-lg font-bold text-slate-900">No published rate matches this request</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm text-slate-600">The selected route, currency, party size, or time is not currently priced. Adjust the search, or ask our team for a tailored quotation.</p>
                    <a href="{{ route('request-quotation', ['service' => 'airport-transfers']) }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Request a quotation</a>
                </div>
            @else
                <form method="POST" action="{{ route('airport-transfer-bookings.store') }}" class="mt-6 grid gap-6 lg:grid-cols-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                    <input type="hidden" name="transfer_type" value="{{ $transferType->value }}">
                    <input type="hidden" name="airport_id" value="{{ $filters['airport_id'] }}">
                    <input type="hidden" name="airport_transfer_location_id" value="{{ $filters['airport_transfer_location_id'] }}">
                    <input type="hidden" name="currency" value="{{ $selectedCurrency }}">
                    <input type="hidden" name="flight_scheduled_at" value="{{ $filters['flight_scheduled_at'] ?? '' }}">
                    <input type="hidden" name="service_starts_at" value="{{ $filters['service_starts_at'] ?? '' }}">
                    <input type="hidden" name="passenger_count" value="{{ $filters['passenger_count'] ?? 1 }}">
                    <input type="hidden" name="luggage_count" value="{{ $filters['luggage_count'] ?? 0 }}">

                    <fieldset class="lg:col-span-2 space-y-4">
                        <legend class="text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Available vehicle classes</legend>
                        @foreach ($options as $index => $rate)
                            <label class="flex cursor-pointer items-start gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition has-[:checked]:border-emerald-600 has-[:checked]:ring-2 has-[:checked]:ring-emerald-600/30">
                                <input type="radio" name="vehicle_type" value="{{ $rate->vehicle_type }}" @checked(old('vehicle_type', $index === 0 ? $rate->vehicle_type : null) === $rate->vehicle_type) required class="mt-1 size-5 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span class="flex-1">
                                    <span class="block text-lg font-black text-emerald-950">{{ str($rate->vehicle_type)->replace('_', ' ')->title() }}</span>
                                    <span class="mt-1 block text-sm text-slate-600">Up to {{ $rate->passenger_capacity }} passengers and {{ $rate->luggage_capacity }} luggage pieces · about {{ $rate->estimated_duration_minutes }} minutes</span>
                                </span>
                                <span class="text-right">
                                    <span class="block text-lg font-black text-emerald-800">{{ Money::format($rate->amount_minor, $rate->currency) }}</span>
                                    <span class="block text-xs font-semibold text-slate-500">per transfer</span>
                                </span>
                            </label>
                        @endforeach
                        <x-input-error :messages="$errors->get('vehicle_type')" class="mt-2" />
                    </fieldset>

                    <div class="space-y-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Trip and contact details</h3>

                        @if ($user !== null && ! $isCustomer)
                            <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900" role="note">You are signed in with a staff account. Transfer requests are submitted by customers; sign out or use the operations console instead.</p>
                        @endif

                        <div>
                            <label for="service-address" class="block text-sm font-semibold text-slate-800">Address in Uganda</label>
                            <textarea id="service-address" name="service_address" rows="2" required maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600" placeholder="Hotel, street address, or agreed meeting point">{{ old('service_address') }}</textarea>
                            <x-input-error :messages="$errors->get('service_address')" class="mt-1" />
                        </div>
                        <div>
                            <label for="flight-number" class="block text-sm font-semibold text-slate-800">Flight number <span class="font-normal text-slate-500">(optional)</span></label>
                            <input id="flight-number" name="flight_number" type="text" maxlength="32" value="{{ old('flight_number') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600" placeholder="KQ 412">
                            <x-input-error :messages="$errors->get('flight_number')" class="mt-1" />
                        </div>
                        <div>
                            <label for="contact-name" class="block text-sm font-semibold text-slate-800">Full name</label>
                            <input id="contact-name" name="contact_name" type="text" required maxlength="180" value="{{ old('contact_name', $user?->name) }}" @readonly($isCustomer) class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
                        </div>
                        <div>
                            <label for="contact-email" class="block text-sm font-semibold text-slate-800">Email</label>
                            <input id="contact-email" name="contact_email" type="email" required maxlength="254" value="{{ old('contact_email', $user?->email) }}" @readonly($isCustomer) class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <x-input-error :messages="$errors->get('contact_email')" class="mt-1" />
                        </div>
                        <div>
                            <label for="contact-phone" class="block text-sm font-semibold text-slate-800">Phone</label>
                            <input id="contact-phone" name="contact_phone" type="tel" required maxlength="40" value="{{ old('contact_phone', $user?->phone) }}" @readonly($isCustomer) class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600" placeholder="+256700000000">
                            <x-input-error :messages="$errors->get('contact_phone')" class="mt-1" />
                        </div>
                        <div>
                            <label for="special-requests" class="block text-sm font-semibold text-slate-800">Special requests <span class="font-normal text-slate-500">(optional)</span></label>
                            <textarea id="special-requests" name="special_requests" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600" placeholder="Child seat, extra stop, meet-and-greet board">{{ old('special_requests') }}</textarea>
                            <x-input-error :messages="$errors->get('special_requests')" class="mt-1" />
                        </div>
                        <div class="flex items-start gap-3 rounded-xl bg-stone-100 p-3">
                            <input id="acknowledge-request" name="acknowledge_request" type="checkbox" value="1" required @checked(old('acknowledge_request')) class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            <label for="acknowledge-request" class="text-sm text-slate-700">I understand this submits a transfer request for review and that no payment is collected online.</label>
                        </div>
                        <x-input-error :messages="$errors->get('acknowledge_request')" class="mt-1" />

                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-6 py-3 text-sm font-black text-emerald-950 transition hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2">Submit transfer request</button>
                    </div>
                </form>
            @endif
        </section>
    @endif
</div>
@endsection
