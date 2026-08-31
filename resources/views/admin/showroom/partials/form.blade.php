@php
    /** @var \App\Models\VehicleListing|null $listing */
    $value = static fn (string $field, mixed $fallback = null) => old(
        $field,
        $listing?->{$field} ?? ($prefill[$field] ?? $fallback),
    );
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
        <p class="font-bold">Please check the form.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h2 class="text-lg font-black text-slate-900">The vehicle</h2>

        <div>
            <label for="title" class="block text-sm font-semibold">Headline</label>
            <input id="title" name="title" type="text" required minlength="4" maxlength="200"
                   value="{{ $value('title') }}"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            <p class="mt-1 text-xs text-slate-500">This is the name buyers see, for example “2016 Toyota Land Cruiser Prado TX”.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="make" class="block text-sm font-semibold">Make</label>
                <input id="make" name="make" type="text" required maxlength="60" value="{{ $value('make') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="model" class="block text-sm font-semibold">Model</label>
                <input id="model" name="model" type="text" required maxlength="80" value="{{ $value('model') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="year" class="block text-sm font-semibold">Year</label>
                <input id="year" name="year" type="number" required min="1950" max="{{ (int) now()->format('Y') + 1 }}"
                       value="{{ $value('year') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="body_type" class="block text-sm font-semibold">Body</label>
                <input id="body_type" name="body_type" type="text" maxlength="32" value="{{ $value('body_type') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="transmission" class="block text-sm font-semibold">Transmission</label>
                <input id="transmission" name="transmission" type="text" maxlength="24" value="{{ $value('transmission') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="fuel_type" class="block text-sm font-semibold">Fuel</label>
                <input id="fuel_type" name="fuel_type" type="text" maxlength="24" value="{{ $value('fuel_type') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="colour" class="block text-sm font-semibold">Colour</label>
                <input id="colour" name="colour" type="text" maxlength="40" value="{{ $value('colour') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="mileage_km" class="block text-sm font-semibold">Mileage (km)</label>
                <input id="mileage_km" name="mileage_km" type="number" min="0" max="2000000" value="{{ $value('mileage_km') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="seating_capacity" class="block text-sm font-semibold">Seats</label>
                <input id="seating_capacity" name="seating_capacity" type="number" min="1" max="100" value="{{ $value('seating_capacity') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>

        <div>
            <label for="condition" class="block text-sm font-semibold">Condition</label>
            <input id="condition" name="condition" type="text" maxlength="24" value="{{ $value('condition') }}"
                   placeholder="Used — good"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
        </div>

        <div>
            <label for="description" class="block text-sm font-semibold">Description</label>
            <textarea id="description" name="description" rows="8" required minlength="30" maxlength="5000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('description') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Buyers decide on this text. Say what has been done to it and what has not.</p>
        </div>

        <div>
            <label for="internal_notes" class="block text-sm font-semibold">Internal notes</label>
            <textarea id="internal_notes" name="internal_notes" rows="3" maxlength="5000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $listing?->internal_notes) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Never shown in the showroom — the floor price, where it came from, what it needs.</p>
        </div>
    </section>

    <aside class="space-y-6 lg:col-span-1">
        <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">Price</h2>

            <div>
                <label for="asking_price" class="block text-sm font-semibold">Asking price</label>
                <input id="asking_price" name="asking_price" type="text" inputmode="numeric" required maxlength="24"
                       value="{{ old('asking_price', $listing ? \App\Support\Money::forInput($listing->asking_price_minor, $listing->currency) : '') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>

            <div>
                <label for="currency" class="block text-sm font-semibold">Currency</label>
                <select id="currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                        <option value="{{ $code }}" @selected(old('currency', $listing?->currency ?? config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                    @endforeach
                </select>
            </div>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_negotiable" value="0">
                <input type="checkbox" name="is_negotiable" value="1" @checked(old('is_negotiable', $listing?->is_negotiable ?? true))
                       class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                <span>
                    <span class="block text-sm font-semibold">Open to offers</span>
                    <span class="block text-xs text-slate-500">Shows an offer box on the enquiry form.</span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_featured" value="0">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $listing?->is_featured ?? false))
                       class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                <span>
                    <span class="block text-sm font-semibold">Feature it</span>
                    <span class="block text-xs text-slate-500">Pinned to the top of the showroom once it is live.</span>
                </span>
            </label>
        </section>

        @if ($listing === null)
            <section class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Fleet vehicle</h2>
                <p class="text-sm text-slate-600">
                    Link this to a vehicle in the hire fleet and selling it will retire it from hire
                    automatically. Leave it blank for stock that was never on hire.
                </p>
                <div>
                    <label for="vehicle_id" class="block text-sm font-semibold">Vehicle</label>
                    <select id="vehicle_id" name="vehicle_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">Not a fleet vehicle</option>
                        @foreach ($vehicles as $option)
                            <option value="{{ $option->getKey() }}" @selected((int) old('vehicle_id', $vehicle?->getKey()) === (int) $option->getKey())>
                                {{ $option->registration_plate }} — {{ $option->year }} {{ $option->make }} {{ $option->model }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        A vehicle with hire bookings still to run cannot be listed.
                    </p>
                </div>
            </section>
        @elseif ($listing->vehicle)
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Fleet vehicle</h2>
                <p class="mt-2 text-sm text-slate-700">
                    Linked to <span class="font-bold">{{ $listing->vehicle->registration_plate }}</span>.
                    Recording the sale retires it from hire.
                </p>
                <p class="mt-2 text-xs text-slate-500">
                    The link cannot be moved to another vehicle — that would rewrite what was advertised.
                </p>
            </section>
        @endif
    </aside>
</div>
