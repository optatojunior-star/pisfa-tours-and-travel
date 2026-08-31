@extends('layouts.public')

@php
    $title = 'Request a quotation';
    $description = 'Ask PISFA to price a tour, transport, vehicle, accommodation, or group travel service. A written quotation follows.';
    $user = auth()->user();
    $currencies = config('pisfa.currency.supported', ['UGX', 'USD']);
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Request a quotation</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black sm:text-5xl">Tell us what a successful journey looks like.</h1>
            <p class="mt-5 max-w-3xl text-lg leading-8 text-emerald-100">
                Describe what you need and we will price it. You will receive a written quotation with a validity
                date, which you can accept or decline. Nothing is charged until you accept.
            </p>
        </div>
    </section>

    <section class="px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[0.7fr_1.3fr]">
            <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-100">
                    <h2 class="text-xl font-black text-emerald-950">Helpful details to include</h2>
                    <ul class="mt-5 space-y-3 text-sm leading-6 text-slate-700">
                        <li>• Preferred dates and flexibility</li>
                        <li>• Destinations or pickup points</li>
                        <li>• Number of travellers or vehicles</li>
                        <li>• Budget range and currency</li>
                        <li>• Accessibility or special requirements</li>
                    </ul>
                </div>
                <div class="rounded-3xl bg-emerald-50 p-7 ring-1 ring-emerald-100">
                    <p class="font-bold text-emerald-950">What happens next</p>
                    <ol class="mt-3 space-y-2 text-sm leading-6 text-slate-600">
                        <li>1. We confirm your request by email with a reference.</li>
                        <li>2. A member of the team prices it and sends a quotation.</li>
                        <li>3. You accept or decline. Accepting produces an invoice.</li>
                    </ol>
                </div>
            </aside>

            <div>
                @if ($errors->any())
                    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800" role="alert">
                        <p class="font-bold">Please check the form.</p>
                        <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('quotation-requests.store') }}"
                      class="space-y-6 rounded-3xl bg-white p-7 shadow-lg ring-1 ring-slate-200">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                    <div>
                        <label for="service" class="block text-sm font-bold text-slate-800">Which service?</label>
                        <select id="service" name="service" required
                                class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Choose a service</option>
                            @foreach ($services as $key => $service)
                                <option value="{{ $key }}" @selected(old('service', $selectedService) === $key)>{{ $service['name'] }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('service')" class="mt-1" />
                    </div>

                    <div>
                        <label for="details" class="block text-sm font-bold text-slate-800">What do you need?</label>
                        <textarea id="details" name="details" rows="6" required minlength="20" maxlength="5000"
                                  placeholder="Dates, destinations, group size, vehicle type, anything else that matters."
                                  class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('details') }}</textarea>
                        <x-input-error :messages="$errors->get('details')" class="mt-1" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="preferred_date" class="block text-sm font-bold text-slate-800">Preferred start date <span class="font-medium text-slate-500">(optional)</span></label>
                            <input id="preferred_date" name="preferred_date" type="date" min="{{ now()->toDateString() }}"
                                   value="{{ old('preferred_date') }}"
                                   class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <x-input-error :messages="$errors->get('preferred_date')" class="mt-1" />
                        </div>
                        <div>
                            <label for="party_size" class="block text-sm font-bold text-slate-800">People or vehicles <span class="font-medium text-slate-500">(optional)</span></label>
                            <input id="party_size" name="party_size" type="number" min="1" max="500" inputmode="numeric"
                                   value="{{ old('party_size') }}"
                                   class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <x-input-error :messages="$errors->get('party_size')" class="mt-1" />
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-[1fr_9rem]">
                        <div>
                            <label for="budget" class="block text-sm font-bold text-slate-800">Approximate budget <span class="font-medium text-slate-500">(optional)</span></label>
                            <input id="budget" name="budget" type="text" inputmode="decimal" maxlength="24"
                                   placeholder="2500000" value="{{ old('budget') }}"
                                   class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <p class="mt-1 text-xs text-slate-500">Digits only, no separators. It helps us pitch the right option.</p>
                            <x-input-error :messages="$errors->get('budget')" class="mt-1" />
                        </div>
                        <div>
                            <label for="budget_currency" class="block text-sm font-bold text-slate-800">Currency</label>
                            <select id="budget_currency" name="budget_currency"
                                    class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                @foreach ($currencies as $currency)
                                    <option value="{{ $currency }}" @selected(old('budget_currency', 'UGX') === $currency)>{{ $currency }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('budget_currency')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <label for="company_name" class="block text-sm font-bold text-slate-800">Organisation <span class="font-medium text-slate-500">(optional)</span></label>
                        <input id="company_name" name="company_name" type="text" maxlength="180"
                               value="{{ old('company_name') }}"
                               class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        <x-input-error :messages="$errors->get('company_name')" class="mt-1" />
                    </div>

                    @if ($user === null)
                        <fieldset class="space-y-5 border-t border-slate-200 pt-6">
                            <legend class="text-sm font-black uppercase tracking-wide text-emerald-800">How we reach you</legend>
                            <div>
                                <label for="contact_name" class="block text-sm font-bold text-slate-800">Your name</label>
                                <input id="contact_name" name="contact_name" type="text" required minlength="2" maxlength="180"
                                       autocomplete="name" value="{{ old('contact_name') }}"
                                       class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <x-input-error :messages="$errors->get('contact_name')" class="mt-1" />
                            </div>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label for="contact_email" class="block text-sm font-bold text-slate-800">Email</label>
                                    <input id="contact_email" name="contact_email" type="email" required maxlength="254"
                                           autocomplete="email" value="{{ old('contact_email') }}"
                                           class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <x-input-error :messages="$errors->get('contact_email')" class="mt-1" />
                                </div>
                                <div>
                                    <label for="contact_phone" class="block text-sm font-bold text-slate-800">Phone</label>
                                    <input id="contact_phone" name="contact_phone" type="tel" required maxlength="40"
                                           autocomplete="tel" placeholder="+256700000000" value="{{ old('contact_phone') }}"
                                           class="mt-2 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <x-input-error :messages="$errors->get('contact_phone')" class="mt-1" />
                                </div>
                            </div>
                            <p class="text-xs leading-5 text-slate-500">
                                You do not need an account. We will email you a private link to follow your request.
                            </p>
                        </fieldset>
                    @else
                        <p class="rounded-2xl bg-stone-100 p-4 text-sm text-slate-700">
                            We will reply to <span class="font-bold">{{ $user->email }}</span>, and the request will
                            appear in your portal.
                        </p>
                    @endif

                    <button type="submit"
                            class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 sm:w-auto">
                        Request a quotation
                    </button>
                </form>
            </div>
        </div>
    </section>
@endsection
