@extends('layouts.public')

@php
    use App\Enums\FlightInquiryScope;
    use App\Enums\FlightTravelClass;
    use App\Enums\FlightTripType;

    $user = auth()->user();
    $isCustomer = $user?->hasRole(\App\Enums\UserRole::Customer) ?? false;
    $currentScope = old('scope', $selectedScope?->value ?? FlightInquiryScope::International->value);
    $currentTripType = old('trip_type', FlightTripType::Return->value);
@endphp

@section('content')
<section class="bg-emerald-950 px-4 py-14 text-white sm:px-6 sm:py-20 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-300">Flights</p>
        <h1 class="mt-3 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl">Domestic and international flight enquiries</h1>
        <p class="mt-5 max-w-2xl text-lg leading-8 text-emerald-100">Tell us where and when you want to fly. A PISFA travel consultant compares fares with our airline partners and replies with options. This is an enquiry — no seat is held and no payment is taken here.</p>
    </div>
</section>

<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <p class="font-bold">Check the enquiry details.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (FlightInquiryScope::cases() as $case)
            <a href="{{ route('flight-inquiries.create.scope', $case->value) }}" @class([
                'rounded-3xl border p-5 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                'border-emerald-600 bg-emerald-50' => $currentScope === $case->value,
                'border-slate-200 bg-white hover:border-emerald-300' => $currentScope !== $case->value,
            ])>
                <span class="block text-lg font-black text-emerald-950">{{ $case->label() }}</span>
                <span class="mt-1 block text-sm text-slate-600">{{ $case->description() }}</span>
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('flight-inquiries.store') }}" class="mt-8 space-y-6 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

        @if ($user !== null && ! $isCustomer)
            <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900" role="note">You are signed in with a staff account. Flight enquiries are submitted by travellers; use the operations console to record one on someone's behalf.</p>
        @endif

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Trip</legend>
            <div>
                <label for="scope" class="block text-sm font-semibold text-slate-800">Flight type</label>
                <select id="scope" name="scope" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (FlightInquiryScope::cases() as $case)
                        <option value="{{ $case->value }}" @selected($currentScope === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('scope')" class="mt-1" />
            </div>
            <div>
                <label for="trip-type" class="block text-sm font-semibold text-slate-800">Trip type</label>
                <select id="trip-type" name="trip_type" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (FlightTripType::cases() as $case)
                        <option value="{{ $case->value }}" @selected($currentTripType === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('trip_type')" class="mt-1" />
            </div>
            <div>
                <label for="origin" class="block text-sm font-semibold text-slate-800">From</label>
                <input id="origin" name="origin" type="text" required minlength="2" maxlength="120" value="{{ old('origin', 'Entebbe') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('origin')" class="mt-1" />
            </div>
            <div>
                <label for="destination" class="block text-sm font-semibold text-slate-800">To</label>
                <input id="destination" name="destination" type="text" required minlength="2" maxlength="120" value="{{ old('destination') }}" placeholder="City or airport" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('destination')" class="mt-1" />
            </div>
            <div>
                <label for="outbound-on" class="block text-sm font-semibold text-slate-800">Outbound date</label>
                <input id="outbound-on" name="outbound_on" type="date" required min="{{ $earliestOutbound->toDateString() }}" max="{{ $latestOutbound->toDateString() }}" value="{{ old('outbound_on') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('outbound_on')" class="mt-1" />
            </div>
            <div>
                <label for="return-on" class="block text-sm font-semibold text-slate-800">Return date <span class="font-normal text-slate-500">(return trips)</span></label>
                <input id="return-on" name="return_on" type="date" max="{{ $latestOutbound->addYear()->toDateString() }}" value="{{ old('return_on') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('return_on')" class="mt-1" />
            </div>
            <div>
                <label for="passenger-count" class="block text-sm font-semibold text-slate-800">Passengers</label>
                <input id="passenger-count" name="passenger_count" type="number" inputmode="numeric" required min="1" max="{{ config('flight_inquiries.maximum_passengers', 50) }}" value="{{ old('passenger_count', 1) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('passenger_count')" class="mt-1" />
            </div>
            <div>
                <label for="travel-class" class="block text-sm font-semibold text-slate-800">Travel class</label>
                <select id="travel-class" name="travel_class" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (FlightTravelClass::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('travel_class', FlightTravelClass::Economy->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('travel_class')" class="mt-1" />
            </div>
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Contact</legend>
            <div>
                <label for="contact-name" class="block text-sm font-semibold text-slate-800">Full name</label>
                <input id="contact-name" name="contact_name" type="text" required minlength="2" maxlength="180" value="{{ old('contact_name', $user?->name) }}" @readonly($isCustomer) class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
            </div>
            <div>
                <label for="contact-email" class="block text-sm font-semibold text-slate-800">Email</label>
                <input id="contact-email" name="contact_email" type="email" required maxlength="254" value="{{ old('contact_email', $user?->email) }}" @readonly($isCustomer) class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_email')" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
                <label for="contact-phone" class="block text-sm font-semibold text-slate-800">Phone</label>
                <input id="contact-phone" name="contact_phone" type="tel" required maxlength="40" value="{{ old('contact_phone', $user?->phone) }}" @readonly($isCustomer) placeholder="+256700000000" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('contact_phone')" class="mt-1" />
            </div>
            <div class="sm:col-span-2">
                <label for="notes" class="block text-sm font-semibold text-slate-800">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="notes" name="notes" rows="4" maxlength="5000" placeholder="Preferred airline, flexible dates, baggage needs, connecting travel" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes') }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>
        </fieldset>

        <div class="flex items-start gap-3 rounded-xl bg-stone-100 p-4">
            <input id="acknowledge-enquiry" name="acknowledge_enquiry" type="checkbox" value="1" required @checked(old('acknowledge_enquiry')) class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
            <label for="acknowledge-enquiry" class="text-sm text-slate-700">I understand this is a fare enquiry. It does not reserve a seat, quote a final price, or collect payment.</label>
        </div>
        <x-input-error :messages="$errors->get('acknowledge_enquiry')" class="-mt-3" />

        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-6 py-3 text-sm font-black text-emerald-950 transition hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 sm:w-auto">Send flight enquiry</button>
    </form>
</div>
@endsection
