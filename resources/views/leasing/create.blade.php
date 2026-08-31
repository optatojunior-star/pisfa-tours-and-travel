@extends('layouts.public')

@php
    $title = 'Lease your car to PISFA';
    $description = 'Put your vehicle to work with PISFA Tours and Travels. Tell us about it, we inspect it, and you are paid a retainer or a share of what it earns.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Vehicle leasing</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black sm:text-5xl">Lease your car to PISFA</h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">
                If your vehicle sits idle most of the week, we will put it to work. You keep ownership; we handle the
                bookings, the drivers, and the paperwork, and you are paid either a fixed monthly retainer or a share
                of what the vehicle actually earns.
            </p>
        </div>
    </section>

    <section class="px-4 py-14 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-3">
            <div class="lg:col-span-1">
                <h2 class="text-2xl font-black text-emerald-950">How it works</h2>
                <ol class="mt-5 space-y-5">
                    <li class="flex gap-4">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 font-black text-emerald-950">1</span>
                        <span>
                            <span class="block font-bold text-slate-900">Tell us about the vehicle</span>
                            <span class="mt-1 block text-sm text-slate-600">The form below. No account needed.</span>
                        </span>
                    </li>
                    <li class="flex gap-4">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 font-black text-emerald-950">2</span>
                        <span>
                            <span class="block font-bold text-slate-900">We come and look at it</span>
                            <span class="mt-1 block text-sm text-slate-600">
                                We arrange an inspection at a time that suits you. Nothing is agreed before somebody
                                has seen the car.
                            </span>
                        </span>
                    </li>
                    <li class="flex gap-4">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 font-black text-emerald-950">3</span>
                        <span>
                            <span class="block font-bold text-slate-900">We agree the terms in writing</span>
                            <span class="mt-1 block text-sm text-slate-600">
                                A retainer or a share, a start date, and a notice period. You sign before anything
                                begins.
                            </span>
                        </span>
                    </li>
                    <li class="flex gap-4">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-400 font-black text-emerald-950">4</span>
                        <span>
                            <span class="block font-bold text-slate-900">You are paid monthly</span>
                            <span class="mt-1 block text-sm text-slate-600">
                                With a statement showing what the vehicle earned and anything deducted.
                            </span>
                        </span>
                    </li>
                </ol>

                <div class="mt-8 rounded-3xl border border-slate-200 bg-white p-6">
                    <h3 class="font-black text-emerald-950">The two ways we pay</h3>
                    <dl class="mt-4 space-y-4">
                        @foreach ($payoutModels as $model)
                            <div>
                                <dt class="text-sm font-bold text-slate-900">{{ $model->label() }}</dt>
                                <dd class="mt-1 text-sm text-slate-600">{{ $model->description() }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <h2 class="text-2xl font-black text-emerald-950">Tell us about your vehicle</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        This is an offer, not an agreement. Nothing is committed until you have seen the terms in
                        writing.
                    </p>

                    @if ($errors->any())
                        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                            <p class="font-bold">Please check the form.</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('leasing.store') }}" class="mt-6 space-y-6">
                        @csrf
                        {{-- Replay protection: a refreshed submission reuses this key and returns
                             the offer already recorded rather than filing a second. --}}
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                        @auth
                            <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
                                Offering as <span class="font-bold">{{ $user->name }}</span> ({{ $user->email }}).
                            </p>
                        @else
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="contact_name" class="block text-sm font-semibold">Your name</label>
                                    <input id="contact_name" name="contact_name" type="text" required maxlength="180"
                                           value="{{ old('contact_name') }}" autocomplete="name"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="contact_email" class="block text-sm font-semibold">Email</label>
                                    <input id="contact_email" name="contact_email" type="email" required maxlength="254"
                                           value="{{ old('contact_email') }}" autocomplete="email"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                            </div>
                        @endauth

                        <div>
                            <label for="contact_phone" class="block text-sm font-semibold">Phone</label>
                            <input id="contact_phone" name="contact_phone" type="tel" required maxlength="40"
                                   value="{{ old('contact_phone', $user?->phone) }}" autocomplete="tel"
                                   placeholder="+256700000000"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>

                        <fieldset class="space-y-4">
                            <legend class="text-lg font-black text-emerald-950">The vehicle</legend>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="make" class="block text-sm font-semibold">Make</label>
                                    <input id="make" name="make" type="text" required maxlength="60"
                                           value="{{ old('make') }}" placeholder="Toyota"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="model" class="block text-sm font-semibold">Model</label>
                                    <input id="model" name="model" type="text" required maxlength="80"
                                           value="{{ old('model') }}" placeholder="Hiace"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="year" class="block text-sm font-semibold">Year</label>
                                    <input id="year" name="year" type="number" required min="1990" max="{{ (int) now()->format('Y') + 1 }}"
                                           value="{{ old('year') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label for="registration_plate" class="block text-sm font-semibold">Registration</label>
                                    <input id="registration_plate" name="registration_plate" type="text" required maxlength="32"
                                           value="{{ old('registration_plate') }}" placeholder="UAX 123K"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="colour" class="block text-sm font-semibold">Colour</label>
                                    <input id="colour" name="colour" type="text" maxlength="40" value="{{ old('colour') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="mileage_km" class="block text-sm font-semibold">Mileage (km)</label>
                                    <input id="mileage_km" name="mileage_km" type="number" min="0" max="2000000"
                                           value="{{ old('mileage_km') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-4">
                                <div>
                                    <label for="transmission" class="block text-sm font-semibold">Transmission</label>
                                    <input id="transmission" name="transmission" type="text" maxlength="24"
                                           value="{{ old('transmission') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="fuel_type" class="block text-sm font-semibold">Fuel</label>
                                    <input id="fuel_type" name="fuel_type" type="text" maxlength="24"
                                           value="{{ old('fuel_type') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="seating_capacity" class="block text-sm font-semibold">Seats</label>
                                    <input id="seating_capacity" name="seating_capacity" type="number" min="1" max="100"
                                           value="{{ old('seating_capacity') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="condition" class="block text-sm font-semibold">Condition</label>
                                    <input id="condition" name="condition" type="text" maxlength="40"
                                           value="{{ old('condition') }}" placeholder="Good"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="space-y-4">
                            <legend class="text-lg font-black text-emerald-950">What you are hoping for</legend>
                            <p class="text-sm text-slate-600">All optional — it just helps us come back with something realistic.</p>

                            <div>
                                <label for="preferred_payout_model" class="block text-sm font-semibold">Preferred arrangement</label>
                                <select id="preferred_payout_model" name="preferred_payout_model"
                                        class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <option value="">No preference</option>
                                    @foreach ($payoutModels as $model)
                                        <option value="{{ $model->value }}" @selected(old('preferred_payout_model') === $model->value)>{{ $model->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="grid grid-cols-3 gap-3">
                                <div class="col-span-2">
                                    <label for="expected_monthly" class="block text-sm font-semibold">Hoped-for monthly amount</label>
                                    <input id="expected_monthly" name="expected_monthly" type="text" inputmode="numeric" maxlength="24"
                                           value="{{ old('expected_monthly') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="expected_currency" class="block text-sm font-semibold">Currency</label>
                                    <select id="expected_currency" name="expected_currency"
                                            class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                            <option value="{{ $code }}" @selected(old('expected_currency', config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label for="available_from" class="block text-sm font-semibold">Available from</label>
                                <input id="available_from" name="available_from" type="date" value="{{ old('available_from') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>

                            <div>
                                <label for="notes" class="block text-sm font-semibold">Anything else</label>
                                <textarea id="notes" name="notes" rows="3" maxlength="2000"
                                          placeholder="Service history, recent work, where the vehicle is kept"
                                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('notes') }}</textarea>
                            </div>
                        </fieldset>

                        <label class="flex items-start gap-3">
                            <input type="checkbox" name="acknowledge_request" value="1" required
                                   class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                            <span class="text-sm text-slate-700">
                                I understand this is an offer, not an agreement. PISFA will inspect the vehicle and
                                send written terms, and nothing begins until I have accepted them.
                            </span>
                        </label>

                        <button type="submit"
                                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-6 text-sm font-bold text-white hover:bg-emerald-900 sm:w-auto">
                            Send my offer
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
@endsection
