@php
    use App\Enums\AirportTransferType;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currencies = config('airport_transfers.currencies', ['UGX', 'USD']);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Airports, locations &amp; transfer rates</h1>
            </div>
            <a href="{{ route('admin.airport-transfer-bookings.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to transfers</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This change was rejected.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="airports-heading">
                <h2 id="airports-heading" class="text-lg font-black text-slate-950">Airports</h2>

                <form method="POST" action="{{ route('admin.airport-transfer-settings.airports.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @csrf
                    <div><label for="airport-code" class="block text-sm font-semibold text-slate-800">IATA/ICAO code</label><input id="airport-code" name="code" type="text" required minlength="3" maxlength="8" value="{{ old('code') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="airport-name" class="block text-sm font-semibold text-slate-800">Name</label><input id="airport-name" name="name" type="text" required maxlength="180" value="{{ old('name') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="airport-city" class="block text-sm font-semibold text-slate-800">City</label><input id="airport-city" name="city" type="text" required maxlength="120" value="{{ old('city') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="airport-country" class="block text-sm font-semibold text-slate-800">Country code</label><input id="airport-country" name="country_code" type="text" required maxlength="2" value="{{ old('country_code', 'UG') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="airport-timezone" class="block text-sm font-semibold text-slate-800">Timezone</label><input id="airport-timezone" name="timezone" type="text" required maxlength="64" value="{{ old('timezone', $timezone) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="airport-sort" class="block text-sm font-semibold text-slate-800">Sort order</label><input id="airport-sort" name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', 0) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div class="sm:col-span-2 lg:col-span-3"><label for="airport-terminal" class="block text-sm font-semibold text-slate-800">Terminal information <span class="font-normal text-slate-500">(optional)</span></label><textarea id="airport-terminal" name="terminal_information" rows="2" maxlength="5000" class="mt-1 block w-full rounded-xl border-slate-300">{{ old('terminal_information') }}</textarea></div>
                    <div class="flex items-center gap-3"><input id="airport-active" name="is_active" type="checkbox" value="1" checked class="size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600"><label for="airport-active" class="text-sm font-semibold text-slate-800">Accepting requests</label></div>
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Add airport</button></div>
                </form>

                @if ($airports->isEmpty())
                    <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No airports yet. Add one before publishing transfer rates.</p>
                @else
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Configured airports</caption>
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr><th scope="col" class="px-3 py-2">Code</th><th scope="col" class="px-3 py-2">Name</th><th scope="col" class="px-3 py-2">City</th><th scope="col" class="px-3 py-2">Status</th><th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($airports as $airport)
                                    <tr>
                                        <td class="px-3 py-2 font-mono font-bold">{{ $airport->code }}</td>
                                        <td class="px-3 py-2">{{ $airport->name }}</td>
                                        <td class="px-3 py-2">{{ $airport->city }}, {{ $airport->country_code }}</td>
                                        <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $airport->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-700' }}">{{ $airport->is_active ? 'Active' : 'Inactive' }}</span></td>
                                        <td class="px-3 py-2 text-right">
                                            <form method="POST" action="{{ route('admin.airport-transfer-settings.airports.status', $airport) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $airport->is_active ? 0 : 1 }}">
                                                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700">{{ $airport->is_active ? 'Deactivate' : 'Activate' }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $airports->links() }}</div>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="locations-heading">
                <h2 id="locations-heading" class="text-lg font-black text-slate-950">Service locations</h2>

                <form method="POST" action="{{ route('admin.airport-transfer-settings.locations.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @csrf
                    <div><label for="location-name" class="block text-sm font-semibold text-slate-800">Name</label><input id="location-name" name="name" type="text" required maxlength="180" value="{{ old('name') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="location-slug" class="block text-sm font-semibold text-slate-800">Slug <span class="font-normal text-slate-500">(optional)</span></label><input id="location-slug" name="slug" type="text" maxlength="200" value="{{ old('slug') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="location-region" class="block text-sm font-semibold text-slate-800">Region</label><input id="location-region" name="region" type="text" required maxlength="120" value="{{ old('region') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div><label for="location-sort" class="block text-sm font-semibold text-slate-800">Sort order</label><input id="location-sort" name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', 0) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                    <div class="sm:col-span-2"><label for="location-description" class="block text-sm font-semibold text-slate-800">Description <span class="font-normal text-slate-500">(optional)</span></label><textarea id="location-description" name="description" rows="2" maxlength="5000" class="mt-1 block w-full rounded-xl border-slate-300">{{ old('description') }}</textarea></div>
                    <div class="flex items-center gap-3"><input id="location-active" name="is_active" type="checkbox" value="1" checked class="size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600"><label for="location-active" class="text-sm font-semibold text-slate-800">Accepting requests</label></div>
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Add location</button></div>
                </form>

                @if ($locations->isEmpty())
                    <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No service locations yet.</p>
                @else
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Configured service locations</caption>
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr><th scope="col" class="px-3 py-2">Name</th><th scope="col" class="px-3 py-2">Region</th><th scope="col" class="px-3 py-2">Status</th><th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($locations as $location)
                                    <tr>
                                        <td class="px-3 py-2 font-semibold">{{ $location->name }}</td>
                                        <td class="px-3 py-2">{{ $location->region }}</td>
                                        <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $location->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-700' }}">{{ $location->is_active ? 'Active' : 'Inactive' }}</span></td>
                                        <td class="px-3 py-2 text-right">
                                            <form method="POST" action="{{ route('admin.airport-transfer-settings.locations.status', $location) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $location->is_active ? 0 : 1 }}">
                                                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700">{{ $location->is_active ? 'Deactivate' : 'Activate' }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $locations->links() }}</div>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="rates-heading">
                <h2 id="rates-heading" class="text-lg font-black text-slate-950">Transfer rates</h2>
                <p class="mt-2 text-sm text-slate-600">Rates are versioned. Publishing a new version closes the previous matching version; existing bookings keep their own price snapshot.</p>

                @if ($airportOptions->isEmpty() || $locationOptions->isEmpty())
                    <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="note">Add at least one airport and one service location before publishing a rate.</p>
                @else
                    <form method="POST" action="{{ route('admin.airport-transfer-settings.rates.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        <div>
                            <label for="rate-airport" class="block text-sm font-semibold text-slate-800">Airport</label>
                            <select id="rate-airport" name="airport_id" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($airportOptions as $airport)
                                    <option value="{{ $airport->id }}" @selected((int) old('airport_id') === $airport->id)>{{ $airport->code }} — {{ $airport->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="rate-location" class="block text-sm font-semibold text-slate-800">Location</label>
                            <select id="rate-location" name="airport_transfer_location_id" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($locationOptions as $location)
                                    <option value="{{ $location->id }}" @selected((int) old('airport_transfer_location_id') === $location->id)>{{ $location->name }} ({{ $location->region }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="rate-type" class="block text-sm font-semibold text-slate-800">Direction</label>
                            <select id="rate-type" name="transfer_type" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach (AirportTransferType::cases() as $case)
                                    <option value="{{ $case->value }}" @selected(old('transfer_type') === $case->value)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label for="rate-vehicle-type" class="block text-sm font-semibold text-slate-800">Vehicle class</label><input id="rate-vehicle-type" name="vehicle_type" type="text" required maxlength="40" value="{{ old('vehicle_type') }}" placeholder="saloon, minivan, coaster" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div>
                            <label for="rate-currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                            <select id="rate-currency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($currencies as $currency)
                                    <option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label for="rate-amount" class="block text-sm font-semibold text-slate-800">Amount</label><input id="rate-amount" name="amount" type="text" inputmode="decimal" required maxlength="24" value="{{ old('amount') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div><label for="rate-passengers" class="block text-sm font-semibold text-slate-800">Passenger capacity</label><input id="rate-passengers" name="passenger_capacity" type="number" required min="1" max="{{ config('airport_transfers.maximum_passengers', 50) }}" value="{{ old('passenger_capacity', 4) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div><label for="rate-luggage" class="block text-sm font-semibold text-slate-800">Luggage capacity</label><input id="rate-luggage" name="luggage_capacity" type="number" required min="0" max="{{ config('airport_transfers.maximum_luggage', 100) }}" value="{{ old('luggage_capacity', 4) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div><label for="rate-duration" class="block text-sm font-semibold text-slate-800">Duration (minutes)</label><input id="rate-duration" name="estimated_duration_minutes" type="number" required min="{{ config('airport_transfers.rate_duration.minimum_minutes', 15) }}" max="{{ config('airport_transfers.rate_duration.maximum_minutes', 1440) }}" value="{{ old('estimated_duration_minutes', 60) }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div><label for="rate-from" class="block text-sm font-semibold text-slate-800">Effective from ({{ $timezone }})</label><input id="rate-from" name="effective_from" type="datetime-local" required value="{{ old('effective_from') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div><label for="rate-until" class="block text-sm font-semibold text-slate-800">Effective until <span class="font-normal text-slate-500">(optional)</span></label><input id="rate-until" name="effective_until" type="datetime-local" value="{{ old('effective_until') }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
                        <div class="flex items-center gap-3"><input id="rate-active" name="is_active" type="checkbox" value="1" checked class="size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600"><label for="rate-active" class="text-sm font-semibold text-slate-800">Active</label></div>
                        <div class="sm:col-span-2 lg:col-span-4"><button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Publish rate version</button></div>
                    </form>
                @endif

                @if ($rates->isEmpty())
                    <p class="mt-6 rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-600">No transfer rates published yet. Customers cannot request a transfer until a rate exists.</p>
                @else
                    <div class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Published transfer rate versions</caption>
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr><th scope="col" class="px-3 py-2">Route</th><th scope="col" class="px-3 py-2">Class</th><th scope="col" class="px-3 py-2">Amount</th><th scope="col" class="px-3 py-2">Capacity</th><th scope="col" class="px-3 py-2">Window</th><th scope="col" class="px-3 py-2">Status</th><th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($rates as $rate)
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="block font-semibold">{{ $rate->airport?->code }} · {{ $rate->location?->name }}</span>
                                            <span class="block text-xs text-slate-500">{{ $rate->transfer_type->label() }}</span>
                                        </td>
                                        <td class="px-3 py-2">{{ str($rate->vehicle_type)->replace('_', ' ')->title() }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap font-semibold">{{ Money::format((int) $rate->amount_minor, $rate->currency) }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $rate->passenger_capacity }} pax · {{ $rate->luggage_capacity }} bags · {{ $rate->estimated_duration_minutes }} min</td>
                                        <td class="px-3 py-2 text-xs">
                                            {{ $rate->effective_from->timezone($timezone)->format('j M Y, H:i') }}
                                            &rarr; {{ $rate->effective_until === null ? 'open' : $rate->effective_until->timezone($timezone)->format('j M Y, H:i') }}
                                        </td>
                                        <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-bold {{ $rate->is_active ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-700' }}">{{ $rate->is_active ? 'Active' : 'Inactive' }}</span></td>
                                        <td class="px-3 py-2 text-right">
                                            <form method="POST" action="{{ route('admin.airport-transfer-settings.rates.status', $rate) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="is_active" value="{{ $rate->is_active ? 0 : 1 }}">
                                                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700">{{ $rate->is_active ? 'Deactivate' : 'Activate' }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $rates->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
