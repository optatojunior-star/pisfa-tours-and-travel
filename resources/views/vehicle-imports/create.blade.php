@extends('layouts.public')

@php
    $user = auth()->user();
    $isCustomer = $user?->hasRole(\App\Enums\UserRole::Customer) ?? false;
    $currencies = config('pisfa.currency.supported', ['UGX', 'USD']);
@endphp

@section('content')
<section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 sm:py-20 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <p class="text-sm font-black uppercase tracking-[0.2em] text-amber-300">Vehicle imports</p>
        <h1 class="mt-3 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl">Import the vehicle you actually want</h1>
        <p class="mt-5 max-w-2xl text-lg leading-8 text-emerald-100">Tell us the specification and your budget. We source from verified auctions and dealers, quote you a landed price, and handle shipping and clearance. You pay a deposit to begin, and the balance when the vehicle is ready.</p>
    </div>
</section>

<div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
    @if ($errors->any())
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
            <p class="font-bold">Check the request details.</p>
            <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('vehicle-imports.store') }}" class="space-y-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

        @if ($user !== null && ! $isCustomer)
            <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900" role="note">You are signed in with a staff account. Import requests are submitted by customers.</p>
        @endif

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Vehicle</legend>
            <div>
                <label for="make" class="block text-sm font-semibold text-slate-800">Make</label>
                <input id="make" name="make" type="text" required minlength="2" maxlength="60" value="{{ old('make') }}" placeholder="Toyota" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('make')" class="mt-1" />
            </div>
            <div>
                <label for="model" class="block text-sm font-semibold text-slate-800">Model</label>
                <input id="model" name="model" type="text" required maxlength="80" value="{{ old('model') }}" placeholder="Harrier" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('model')" class="mt-1" />
            </div>
            <div>
                <label for="year-from" class="block text-sm font-semibold text-slate-800">Year from</label>
                <input id="year-from" name="year_from" type="number" required min="1980" max="{{ $currentYear + 1 }}" value="{{ old('year_from', $currentYear - 6) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('year_from')" class="mt-1" />
            </div>
            <div>
                <label for="year-to" class="block text-sm font-semibold text-slate-800">Year to</label>
                <input id="year-to" name="year_to" type="number" required min="1980" max="{{ $currentYear + 1 }}" value="{{ old('year_to', $currentYear - 3) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('year_to')" class="mt-1" />
            </div>
            @foreach ([
                'body_type' => ['Body type', $bodyTypes],
                'fuel_type' => ['Fuel', $fuelTypes],
                'transmission' => ['Transmission', $transmissions],
                'drive_type' => ['Drive', $driveTypes],
                'steering' => ['Steering', $steeringOptions],
                'origin_country' => ['Import from', $originCountries],
            ] as $field => [$label, $options])
                <div>
                    <label for="{{ $field }}" class="block text-sm font-semibold text-slate-800">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        @foreach ($options as $value => $optionLabel)
                            <option value="{{ $value }}" @selected(old($field) === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get($field)" class="mt-1" />
                </div>
            @endforeach
            <div>
                <label for="engine" class="block text-sm font-semibold text-slate-800">Engine (cc) <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="engine" name="engine_capacity_cc" type="number" min="600" max="10000" value="{{ old('engine_capacity_cc') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('engine_capacity_cc')" class="mt-1" />
            </div>
            <div>
                <label for="mileage" class="block text-sm font-semibold text-slate-800">Maximum mileage (km) <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="mileage" name="maximum_mileage_km" type="number" min="0" max="1000000" value="{{ old('maximum_mileage_km') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('maximum_mileage_km')" class="mt-1" />
            </div>
            <div>
                <label for="grade" class="block text-sm font-semibold text-slate-800">Minimum auction grade <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="grade" name="auction_grade" type="text" maxlength="16" value="{{ old('auction_grade') }}" placeholder="4.0" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="colour" class="block text-sm font-semibold text-slate-800">Preferred colour <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="colour" name="preferred_colour" type="text" maxlength="40" value="{{ old('preferred_colour') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </fieldset>

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-bold uppercase tracking-[0.14em] text-emerald-700">Budget and purpose</legend>
            <div>
                <label for="budget" class="block text-sm font-semibold text-slate-800">Budget</label>
                <input id="budget" name="budget" type="text" inputmode="decimal" required maxlength="24" value="{{ old('budget') }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">Your all-in budget, landed in Uganda.</p>
                <x-input-error :messages="$errors->get('budget')" class="mt-1" />
            </div>
            <div>
                <label for="budget-currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                <select id="budget-currency" name="budget_currency" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($currencies as $currency)
                        <option value="{{ $currency }}" @selected(old('budget_currency') === $currency)>{{ $currency }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="units" class="block text-sm font-semibold text-slate-800">Number of units</label>
                <input id="units" name="units" type="number" required min="1" max="{{ config('vehicle_imports.maximum_units', 20) }}" value="{{ old('units', 1) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('units')" class="mt-1" />
            </div>
            <div>
                <label for="purpose" class="block text-sm font-semibold text-slate-800">Purpose</label>
                <select id="purpose" name="purpose" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach ($purposes as $value => $label)
                        <option value="{{ $value }}" @selected(old('purpose') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="notes" class="block text-sm font-semibold text-slate-800">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="notes" name="notes" rows="3" maxlength="5000" placeholder="Must-have features, deal-breakers, timing" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes') }}</textarea>
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
        </fieldset>

        <div class="flex items-start gap-3 rounded-xl bg-stone-100 p-4">
            <input id="acknowledge-request" name="acknowledge_request" type="checkbox" value="1" required @checked(old('acknowledge_request')) class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
            <label for="acknowledge-request" class="text-sm text-slate-700">I understand this is a sourcing request. Nothing is ordered and no payment is due until I accept a quotation.</label>
        </div>
        <x-input-error :messages="$errors->get('acknowledge_request')" class="-mt-6" />

        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-500 px-6 py-3 text-sm font-black text-emerald-950 transition hover:bg-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-700 focus-visible:ring-offset-2 sm:w-auto">Request a quotation</button>
    </form>
</div>
@endsection
