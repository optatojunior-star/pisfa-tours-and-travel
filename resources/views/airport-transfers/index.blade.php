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
<section class="bg-emerald-950 px-4 py-14 text-white sm:px-6 sm:py-20 lg:px-8">
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

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="transfer-quote-heading">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 1</p>
                <h2 id="transfer-quote-heading" class="mt-1 text-xl font-black text-emerald-950">Price your transfer</h2>
            </div>
            <a href="{{ route('airport-transfers.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Clear</a>
        </div>

        <form method="GET" action="{{ route('airport-transfers.index') }}" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="transfer-type" class="block text-sm font-semibold text-slate-800">Direction</label>
                <select id="transfer-type" name="transfer_type" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Select direction</option>
                    @foreach (AirportTransferType::cases() as $case)
                        <option value="{{ $case->value }}" @selected($selectedType === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transfer-airport" class="block text-sm font-semibold text-slate-800">Airport</label>
                <select id="transfer-airport" name="airport_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Select airport</option>
                    @foreach ($airports as $airport)
                        <option value="{{ $airport->id }}" @selected((int) ($filters['airport_id'] ?? 0) === $airport->id)>{{ $airport->code }} — {{ $airport->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transfer-location" class="block text-sm font-semibold text-slate-800">Service location</label>
                <select id="transfer-location" name="airport_transfer_location_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Select location</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((int) ($filters['airport_transfer_location_id'] ?? 0) === $location->id)>{{ $location->name }} ({{ $location->region }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="transfer-currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                <select id="transfer-currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="flight-scheduled-at" class="block text-sm font-semibold text-slate-800">Flight time (Uganda time)</label>
                <input id="flight-scheduled-at" name="flight_scheduled_at" type="datetime-local" value="{{ $filters['flight_scheduled_at'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600" aria-describedby="flight-scheduled-help">
                <p id="flight-scheduled-help" class="mt-1 text-xs text-slate-600">Landing time for a pickup, departure time for a drop-off.</p>
            </div>
            <div>
                <label for="service-starts-at" class="block text-sm font-semibold text-slate-800">Address pickup (drop-off only)</label>
                <input id="service-starts-at" name="service_starts_at" type="datetime-local" min="{{ $earliestValue }}" value="{{ $filters['service_starts_at'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="passenger-count" class="block text-sm font-semibold text-slate-800">Passengers</label>
                <input id="passenger-count" name="passenger_count" type="number" inputmode="numeric" min="1" max="{{ config('airport_transfers.maximum_passengers', 50) }}" value="{{ $filters['passenger_count'] ?? 1 }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="luggage-count" class="block text-sm font-semibold text-slate-800">Luggage pieces</label>
                <input id="luggage-count" name="luggage_count" type="number" inputmode="numeric" min="0" max="{{ config('airport_transfers.maximum_luggage', 100) }}" value="{{ $filters['luggage_count'] ?? 0 }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-6 py-3 text-sm font-bold text-white transition hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2">Show transfer prices</button>
            </div>
        </form>
    </section>

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
