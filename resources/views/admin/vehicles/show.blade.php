@php
    use App\Enums\VehicleCatalogueStatus;
    use App\Enums\VehicleOperationalStatus;
    use App\Support\Money;
    use App\Support\Publishing\VehicleReadiness;
    use App\Support\VehicleSpecification;

    $catalogue = $vehicle->catalogue_status;
    $operational = $vehicle->operational_status;
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $readiness = VehicleReadiness::for($vehicle);
    $isPublished = $catalogue === VehicleCatalogueStatus::Published;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('admin.vehicles.index') }}" class="text-sm font-bold text-emerald-800 underline">Back to vehicles</a>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold">{{ $catalogue->label() }}</span>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">{{ $operational->label() }}</span>
                </div>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}</h1>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ $vehicle->registration_plate }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($isPublished)
                    <a href="{{ route('car-hire.show', $vehicle) }}" target="_blank" rel="noopener"
                       class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">View public page</a>
                @endif
                <a href="{{ route('admin.vehicles.edit', $vehicle) }}"
                   class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 text-sm font-bold text-white">Edit vehicle</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900" role="alert" tabindex="-1">
                    <p class="font-bold">That change was not saved.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="vehicle-admin-details">
                        <h2 id="vehicle-admin-details" class="text-xl font-black text-emerald-950">Specification</h2>
                        <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ([
                                ['Body type', VehicleSpecification::label(VehicleSpecification::bodyTypes(), $vehicle->vehicle_type)],
                                ['Condition', VehicleSpecification::label(VehicleSpecification::conditions(), $vehicle->condition)],
                                ['Engine', VehicleSpecification::formatEngine($vehicle->engine_cc) ?? 'Not stated'],
                                ['Fuel', VehicleSpecification::label(VehicleSpecification::fuelTypes(), $vehicle->fuel_type)],
                                ['Transmission', VehicleSpecification::label(VehicleSpecification::transmissions(), $vehicle->transmission)],
                                ['Drive', VehicleSpecification::label(VehicleSpecification::driveTypes(), $vehicle->drive_type)],
                                ['Seats', $vehicle->seating_capacity],
                                ['Luggage', $vehicle->luggage_capacity],
                                ['Bookings', $vehicle->bookings_count],
                            ] as [$label, $value])
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                                    <dd class="mt-1 font-semibold text-slate-900">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-5 text-sm leading-6 text-slate-700">{{ $vehicle->summary }}</p>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="vehicle-rate-history">
                        <h2 id="vehicle-rate-history" class="text-xl font-black text-emerald-950">Prices</h2>
                        @if ($vehicle->hireRates->isEmpty())
                            <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                                No price yet. Add one below — a vehicle cannot be published without a price customers can be charged.
                            </p>
                        @else
                            <div class="mt-5 overflow-x-auto">
                                <table class="min-w-full text-left text-sm">
                                    <caption class="sr-only">Vehicle price versions, newest first</caption>
                                    <thead class="border-b text-xs uppercase text-slate-500">
                                        <tr>
                                            <th class="px-3 py-2">Currency</th>
                                            <th class="px-3 py-2">Self-drive / day</th>
                                            <th class="px-3 py-2">With driver / day</th>
                                            <th class="px-3 py-2">Deposit</th>
                                            <th class="px-3 py-2">Applies</th>
                                            <th class="px-3 py-2">State</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        @foreach ($vehicle->hireRates as $rate)
                                            @php($live = $rate->isEffectiveAt(now()))
                                            <tr @class(['bg-emerald-50/60' => $live])>
                                                <td class="px-3 py-3 font-bold">{{ $rate->currency }}</td>
                                                <td class="px-3 py-3">{{ $rate->self_drive_daily_minor === null ? '—' : Money::format((int) $rate->self_drive_daily_minor, $rate->currency) }}</td>
                                                <td class="px-3 py-3">{{ $rate->with_driver_daily_minor === null ? '—' : Money::format((int) $rate->with_driver_daily_minor, $rate->currency) }}</td>
                                                <td class="px-3 py-3">{{ Money::format((int) $rate->security_deposit_minor, $rate->currency) }}</td>
                                                <td class="whitespace-nowrap px-3 py-3">
                                                    {{ $rate->effective_from->timezone($timezone)->format('j M Y H:i') }}
                                                    <br><span class="text-xs text-slate-500">to {{ $rate->effective_until?->timezone($timezone)->format('j M Y H:i') ?? 'no end date' }}</span>
                                                </td>
                                                <td class="px-3 py-3">
                                                    @if ($live)
                                                        <span class="rounded-full bg-emerald-700 px-2 py-0.5 text-xs font-bold text-white">In force now</span>
                                                    @elseif (! $rate->is_active)
                                                        <span class="text-xs font-semibold text-slate-500">Switched off</span>
                                                    @elseif ($rate->effective_from->isFuture())
                                                        <span class="text-xs font-semibold text-amber-700">Starts later</span>
                                                    @else
                                                        <span class="text-xs font-semibold text-slate-500">Expired</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>

                    <section class="scroll-mt-24 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"
                             aria-labelledby="new-rate-heading" id="new-rate">
                        <h2 id="new-rate-heading" class="text-xl font-black text-emerald-950">Add a price</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            Prices are never edited, only superseded — a new price in the same currency closes the one before it,
                            and bookings already taken keep the price they were quoted.
                        </p>

                        <x-input-error :messages="$errors->get('rates')" class="mt-3" />

                        <form method="POST" action="{{ route('admin.vehicles.rates.store', $vehicle) }}" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @csrf
                            <div>
                                <label for="rate-currency" class="block text-sm font-semibold">Currency</label>
                                <select id="rate-currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300">
                                    @foreach (config('car_hire.currencies', ['UGX', 'USD']) as $code)
                                        <option value="{{ $code }}" @selected(old('currency') === $code)>{{ $code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="rate-self-drive" class="block text-sm font-semibold">Self-drive / day</label>
                                <input id="rate-self-drive" name="self_drive_daily" inputmode="decimal" value="{{ old('self_drive_daily') }}"
                                       placeholder="Leave blank if not offered" class="mt-1 block w-full rounded-xl border-slate-300">
                                <x-input-error :messages="$errors->get('self_drive_daily')" class="mt-1" />
                            </div>
                            <div>
                                <label for="rate-with-driver" class="block text-sm font-semibold">With driver / day</label>
                                <input id="rate-with-driver" name="with_driver_daily" inputmode="decimal" value="{{ old('with_driver_daily') }}"
                                       placeholder="Leave blank if not offered" class="mt-1 block w-full rounded-xl border-slate-300">
                                <x-input-error :messages="$errors->get('with_driver_daily')" class="mt-1" />
                            </div>
                            <div>
                                <label for="rate-deposit" class="block text-sm font-semibold">Security deposit</label>
                                <input id="rate-deposit" name="security_deposit" inputmode="decimal" value="{{ old('security_deposit', '0') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300">
                            </div>
                            <div>
                                <label for="rate-from" class="block text-sm font-semibold">Applies from</label>
                                <input id="rate-from" name="effective_from" type="datetime-local" required
                                       value="{{ old('effective_from', now($timezone)->format('Y-m-d\TH:i')) }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300" aria-describedby="rate-from-help">
                                <p id="rate-from-help" class="mt-1 text-xs text-slate-500">Today's date and time, unless the price starts later.</p>
                            </div>
                            <div>
                                <label for="rate-until" class="block text-sm font-semibold">Applies until <span class="font-normal text-slate-500">(optional)</span></label>
                                <input id="rate-until" name="effective_until" type="datetime-local" value="{{ old('effective_until') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300" aria-describedby="rate-until-help">
                                <p id="rate-until-help" class="mt-1 text-xs text-slate-500">Leave empty so the price does not expire on its own.</p>
                            </div>
                            <input type="hidden" name="is_active" value="1">
                            <div class="sm:col-span-2 lg:col-span-3">
                                <button class="min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white">Save this price</button>
                            </div>
                        </form>
                    </section>
                </div>

                <aside class="space-y-6 lg:sticky lg:top-6 lg:self-start">
                    {{--
                        The publication checklist.

                        Publishing used to fail with "A published vehicle needs a
                        currently effective supported rate for at least one hire
                        mode" — a sentence that names three pieces of jargon, is
                        raised against a dropdown at the top of a different form,
                        and does not say which of five possible conditions is the
                        one that failed.

                        Now the rules are visible before you press the button,
                        each says what is wrong and what to do about it, and each
                        links to the place on this page where it is fixed.
                    --}}
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="publish-checklist-heading">
                        <h2 id="publish-checklist-heading" class="font-black text-slate-950">Ready to publish?</h2>

                        @if ($readiness->isReady())
                            <p class="mt-2 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">
                                @if ($isPublished)
                                    This vehicle is live and still meets every requirement.
                                @else
                                    Everything is in place. You can publish it.
                                @endif
                            </p>
                        @else
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $readiness->passedCount() }} of {{ $readiness->total() }} done.
                                {{ count($readiness->failures()) === 1 ? 'One thing is' : count($readiness->failures()).' things are' }} still missing.
                            </p>
                        @endif

                        <ul class="mt-4 space-y-3">
                            @foreach ($readiness->checks as $check)
                                <li class="flex gap-3">
                                    <span aria-hidden="true" @class([
                                        'mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-xs font-black',
                                        'bg-emerald-600 text-white' => $check->passed,
                                        'bg-amber-500 text-white' => ! $check->passed,
                                    ])>{{ $check->passed ? '✓' : '!' }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-bold text-slate-900">
                                            {{ $check->label }}
                                            <span class="sr-only">— {{ $check->passed ? 'done' : 'still to do' }}</span>
                                        </span>
                                        @if ($check->passed)
                                            <span class="mt-0.5 block text-xs text-slate-500">{{ $check->problem }}</span>
                                        @else
                                            <span class="mt-0.5 block text-xs leading-5 text-slate-700">{{ $check->problem }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-slate-600">{{ $check->fix }}</span>
                                            @if ($check->anchor)
                                                <a href="{{ $check->key === 'rate' ? '#new-rate' : route('admin.vehicles.edit', $vehicle).'#'.$check->anchor }}"
                                                   class="mt-1 inline-block text-xs font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-2">
                                                    {{ $check->actionLabel }}
                                                </a>
                                            @endif
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="catalogue-state-heading">
                        <h2 id="catalogue-state-heading" class="font-black text-slate-950">Catalogue state</h2>
                        <div class="mt-4 space-y-2">
                            @foreach ($catalogue->allowedTransitions() as $next)
                                @php($blocked = $next === VehicleCatalogueStatus::Published && ! $readiness->isReady())
                                <form method="POST" action="{{ route('admin.vehicles.status', $vehicle) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="catalogue_status" value="{{ $next->value }}">
                                    <button @class([
                                        'min-h-11 w-full rounded-xl px-4 text-sm font-bold',
                                        'bg-emerald-700 text-white' => $next === VehicleCatalogueStatus::Published && ! $blocked,
                                        'cursor-not-allowed bg-slate-200 text-slate-500' => $blocked,
                                        'bg-slate-100 text-slate-800' => $next !== VehicleCatalogueStatus::Published,
                                    ]) @disabled($blocked)
                                       @if ($blocked) aria-describedby="publish-checklist-heading" title="Finish the checklist above first" @endif>
                                        {{ $next === VehicleCatalogueStatus::Published ? 'Publish' : ($next === VehicleCatalogueStatus::Archived ? 'Archive' : 'Restore to draft') }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="operations-state-heading">
                        <h2 id="operations-state-heading" class="font-black text-slate-950">Operational state</h2>
                        <p class="mt-2 text-sm text-slate-600">Anything but Available hides the vehicle from public booking.</p>
                        <form method="POST" action="{{ route('admin.vehicles.status', $vehicle) }}" class="mt-4">
                            @csrf @method('PATCH')
                            <label for="quick-operational-status" class="sr-only">Operational status</label>
                            <select id="quick-operational-status" name="operational_status" class="block w-full rounded-xl border-slate-300">
                                @foreach (VehicleOperationalStatus::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($case === $operational)>{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            <button class="mt-3 min-h-11 w-full rounded-xl bg-slate-900 px-4 text-sm font-bold text-white">Save operational state</button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="font-black text-slate-950">Photographs</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            {{ $vehicle->media->count() }} uploaded, {{ $vehicle->media->where('is_cover', true)->count() }} set as cover.
                        </p>
                        <a href="{{ route('admin.vehicles.edit', $vehicle) }}#vehicle-media-heading"
                           class="mt-2 inline-block text-sm font-bold text-emerald-800 underline">Manage photographs</a>
                    </section>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
