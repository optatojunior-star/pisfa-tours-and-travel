@php
    use App\Enums\MaintenanceStatus;
    use App\Enums\MaintenanceType;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $currencies = config('pisfa.currency.supported', ['UGX', 'USD']);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Fleet</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $vehicle->make }} {{ $vehicle->model }}</h1>
                <p class="mt-1 font-mono text-sm text-slate-600">{{ $vehicle->registration_plate }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('create', \App\Models\VehicleListing::class)
                    <a href="{{ route('admin.showroom.create', ['vehicle' => $vehicle->getKey()]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Sell this vehicle</a>
                @endcan
                <a href="{{ route('admin.fleet.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to fleet</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Vehicle at a glance">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Odometer</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $vehicle->formattedOdometer() }}</p>
                    @if ($vehicle->odometer_updated_at)
                        <p class="mt-1 text-xs text-slate-500">Updated {{ $vehicle->odometer_updated_at->timezone($timezone)->format('j M Y') }}</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</p>
                    <p class="mt-1 text-lg font-black text-slate-900">{{ $vehicle->operational_status->label() }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $openJobs->count() }} open {{ str('job')->plural($openJobs->count()) }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Fuel economy</p>
                    @if ($consumption['litres_per_100km'] !== null)
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ number_format($consumption['litres_per_100km'], 1) }} <span class="text-sm font-medium">L/100km</span></p>
                        <p class="mt-1 text-xs text-slate-500">over {{ number_format($consumption['distance_km']) }} km</p>
                    @else
                        <p class="mt-1 text-lg font-black text-slate-400">Not enough data</p>
                        <p class="mt-1 text-xs text-slate-500">Needs two full-tank fills</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Running cost, {{ $costWindowMonths }} months</p>
                    @if ($costs['by_currency'] === [])
                        <p class="mt-1 text-lg font-black text-slate-400">Nothing recorded</p>
                    @else
                        @foreach ($costs['by_currency'] as $currency => $row)
                            <p class="mt-1 text-xl font-black text-slate-900">{{ Money::format($row['total_minor'], $currency) }}</p>
                            <p class="text-xs text-slate-500">
                                {{ Money::format($row['maintenance_minor'], $currency) }} maintenance ·
                                {{ Money::format($row['fuel_minor'], $currency) }} fuel
                            </p>
                        @endforeach
                    @endif
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="jobs-heading">
                        <h2 id="jobs-heading" class="text-lg font-black text-slate-950">Maintenance</h2>

                        @if ($vehicle->maintenanceRecords->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">Nothing has been recorded for this vehicle.</p>
                        @else
                            <ul class="mt-4 space-y-4">
                                @foreach ($vehicle->maintenanceRecords as $record)
                                    <li class="rounded-2xl border border-slate-200 p-5">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="font-bold text-slate-900">{{ $record->title }}</p>
                                                <p class="mt-1 text-xs text-slate-500">
                                                    {{ $record->type->label() }}
                                                    · {{ $record->reference }}
                                                    @if ($record->vendor) · {{ $record->vendor }} @endif
                                                </p>
                                            </div>
                                            <span @class([
                                                'rounded-full px-3 py-1 text-xs font-bold',
                                                'bg-sky-50 text-sky-800' => $record->status->tone() === 'sky',
                                                'bg-amber-50 text-amber-900' => $record->status->tone() === 'amber',
                                                'bg-emerald-50 text-emerald-800' => $record->status->tone() === 'emerald',
                                                'bg-rose-50 text-rose-800' => $record->status->tone() === 'rose',
                                            ])>{{ $record->status->label() }}</span>
                                        </div>

                                        <dl class="mt-3 grid gap-3 text-xs sm:grid-cols-4">
                                            <div>
                                                <dt class="text-slate-500">Scheduled</dt>
                                                <dd class="text-slate-800">{{ $record->scheduled_for?->format('j M Y') ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-slate-500">Completed</dt>
                                                <dd class="text-slate-800">{{ $record->completed_at?->timezone($timezone)->format('j M Y') ?? '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-slate-500">Odometer</dt>
                                                <dd class="text-slate-800">{{ $record->odometer_km ? number_format($record->odometer_km).' km' : '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="text-slate-500">Cost</dt>
                                                <dd class="font-semibold text-slate-900">{{ $record->status->countsTowardsCost() ? $record->formattedCost() : '—' }}</dd>
                                            </div>
                                        </dl>

                                        @if ($record->isDue())
                                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs font-semibold text-amber-900">
                                                Next {{ $record->type->label() }} {{ $record->dueReason() }}
                                            </p>
                                        @elseif ($record->next_due_on || $record->next_due_odometer_km)
                                            <p class="mt-3 text-xs text-slate-500">
                                                Next due
                                                @if ($record->next_due_on) {{ $record->next_due_on->format('j M Y') }} @endif
                                                @if ($record->next_due_odometer_km) at {{ number_format($record->next_due_odometer_km) }} km @endif
                                            </p>
                                        @endif

                                        @if ($record->closure_reason)
                                            <p class="mt-3 text-xs text-slate-600"><span class="font-bold">Cancelled:</span> {{ $record->closure_reason }}</p>
                                        @endif

                                        @if ($record->status->isOpen())
                                            <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                                                @if ($record->status === MaintenanceStatus::Scheduled)
                                                    <form method="POST" action="{{ route('admin.fleet.maintenance.start', $record) }}">
                                                        @csrf
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Start work</button>
                                                    </form>
                                                @endif

                                                <details class="w-full">
                                                    <summary class="inline-flex min-h-11 cursor-pointer items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Close this job</summary>
                                                    <form method="POST" action="{{ route('admin.fleet.maintenance.complete', $record) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                                        @csrf
                                                        <div>
                                                            <label for="odo-{{ $record->id }}" class="block text-xs font-semibold text-slate-700">Odometer at completion (km)</label>
                                                            <input id="odo-{{ $record->id }}" name="odometer_km" type="number" min="{{ $vehicle->current_odometer_km }}" required
                                                                   value="{{ old('odometer_km', $vehicle->current_odometer_km) }}"
                                                                   class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            <p class="mt-1 text-xs text-slate-500">Cannot be below the recorded {{ $vehicle->formattedOdometer() }}.</p>
                                                        </div>
                                                        <div>
                                                            <label for="cost-{{ $record->id }}" class="block text-xs font-semibold text-slate-700">Cost ({{ $record->currency }})</label>
                                                            <input id="cost-{{ $record->id }}" name="cost" type="text" inputmode="decimal" required
                                                                   value="{{ old('cost') }}"
                                                                   class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            <p class="mt-1 text-xs text-slate-500">Enter 0 if it was under warranty.</p>
                                                        </div>
                                                        @if ($record->type->recurs())
                                                            <div>
                                                                <label for="due-on-{{ $record->id }}" class="block text-xs font-semibold text-slate-700">Next due on</label>
                                                                <input id="due-on-{{ $record->id }}" name="next_due_on" type="date" min="{{ now()->addDay()->toDateString() }}"
                                                                       class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            </div>
                                                            <div>
                                                                <label for="due-km-{{ $record->id }}" class="block text-xs font-semibold text-slate-700">Next due at (km)</label>
                                                                <input id="due-km-{{ $record->id }}" name="next_due_odometer_km" type="number" min="1"
                                                                       class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            </div>
                                                        @endif
                                                        <div class="sm:col-span-2">
                                                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Record completion</button>
                                                        </div>
                                                    </form>

                                                    <form method="POST" action="{{ route('admin.fleet.maintenance.cancel', $record) }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-slate-200 pt-4">
                                                        @csrf
                                                        <div class="min-w-56 flex-1">
                                                            <label for="cancel-{{ $record->id }}" class="block text-xs font-semibold text-slate-700">Cancel instead</label>
                                                            <input id="cancel-{{ $record->id }}" name="reason" type="text" minlength="5" maxlength="255" required
                                                                   placeholder="Why is it not going ahead?"
                                                                   class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                        </div>
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-rose-300 px-4 text-sm font-bold text-rose-700 hover:bg-rose-50">Cancel job</button>
                                                    </form>
                                                </details>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="fuel-heading">
                        <h2 id="fuel-heading" class="text-lg font-black text-slate-950">Fuel</h2>

                        @if ($vehicle->fuelLogs->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">No refuelling has been recorded.</p>
                        @else
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <caption class="sr-only">Fuel log</caption>
                                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th scope="col" class="py-2 pr-4">Date</th>
                                            <th scope="col" class="py-2 pr-4 text-right">Odometer</th>
                                            <th scope="col" class="py-2 pr-4 text-right">Volume</th>
                                            <th scope="col" class="py-2 pr-4 text-right">Cost</th>
                                            <th scope="col" class="py-2 pr-4 text-right">Per litre</th>
                                            <th scope="col" class="py-2">Tank</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($vehicle->fuelLogs as $log)
                                            <tr>
                                                <td class="py-2 pr-4 text-slate-700">{{ $log->filled_at->timezone($timezone)->format('j M Y') }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums text-slate-700">{{ number_format($log->odometer_km) }} km</td>
                                                <td class="py-2 pr-4 text-right tabular-nums text-slate-700">{{ $log->formattedVolume() }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums font-semibold text-slate-900">{{ $log->formattedCost() }}</td>
                                                <td class="py-2 pr-4 text-right tabular-nums text-slate-500">{{ $log->formattedPricePerLitre() }}</td>
                                                <td class="py-2 text-xs text-slate-500">{{ $log->is_full_tank ? 'Full' : 'Partial' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="schedule-heading">
                        <h2 id="schedule-heading" class="text-lg font-black text-slate-950">Schedule maintenance</h2>
                        <form method="POST" action="{{ route('admin.fleet.maintenance.store', $vehicle) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label for="mtype" class="block text-sm font-semibold text-slate-800">Type</label>
                                <select id="mtype" name="type" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    @foreach (MaintenanceType::cases() as $case)
                                        <option value="{{ $case->value }}" @selected(old('type') === $case->value)>{{ $case->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="mtitle" class="block text-sm font-semibold text-slate-800">What is being done</label>
                                <input id="mtitle" name="title" type="text" required minlength="3" maxlength="180"
                                       value="{{ old('title') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div>
                                <label for="mdate" class="block text-sm font-semibold text-slate-800">Scheduled for</label>
                                <input id="mdate" name="scheduled_for" type="date" value="{{ old('scheduled_for') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div>
                                <label for="mvendor" class="block text-sm font-semibold text-slate-800">Workshop</label>
                                <input id="mvendor" name="vendor" type="text" maxlength="180" value="{{ old('vendor') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div>
                                <label for="mcurrency" class="block text-sm font-semibold text-slate-800">Currency</label>
                                <select id="mcurrency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    @foreach ($currencies as $currency)
                                        <option value="{{ $currency }}" @selected(old('currency', 'UGX') === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500">The cost is recorded when the job is closed.</p>
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Schedule</button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="fuel-form-heading">
                        <h2 id="fuel-form-heading" class="text-lg font-black text-slate-950">Record a refuelling</h2>
                        <form method="POST" action="{{ route('admin.fleet.fuel.store', $vehicle) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label for="fdate" class="block text-sm font-semibold text-slate-800">Filled at</label>
                                <input id="fdate" name="filled_at" type="datetime-local" required
                                       max="{{ now($timezone)->format('Y-m-d\TH:i') }}"
                                       value="{{ old('filled_at', now($timezone)->format('Y-m-d\TH:i')) }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div>
                                <label for="fodo" class="block text-sm font-semibold text-slate-800">Odometer (km)</label>
                                <input id="fodo" name="odometer_km" type="number" required min="{{ $vehicle->current_odometer_km }}"
                                       value="{{ old('odometer_km', $vehicle->current_odometer_km) }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <p class="mt-1 text-xs text-slate-500">Cannot be below the recorded {{ $vehicle->formattedOdometer() }}.</p>
                            </div>
                            <div>
                                <label for="flitres" class="block text-sm font-semibold text-slate-800">Litres</label>
                                <input id="flitres" name="litres" type="number" step="0.01" min="0.01" max="2000" required
                                       value="{{ old('litres') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="fcost" class="block text-sm font-semibold text-slate-800">Cost</label>
                                    <input id="fcost" name="cost" type="text" inputmode="decimal" required value="{{ old('cost') }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="fcurrency" class="block text-sm font-semibold text-slate-800">Currency</label>
                                    <select id="fcurrency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        @foreach ($currencies as $currency)
                                            <option value="{{ $currency }}" @selected(old('currency', 'UGX') === $currency)>{{ $currency }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="fstation" class="block text-sm font-semibold text-slate-800">Station</label>
                                <input id="fstation" name="station" type="text" maxlength="180" value="{{ old('station') }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <label class="flex items-center gap-2">
                                <input type="hidden" name="is_full_tank" value="0">
                                <input type="checkbox" name="is_full_tank" value="1" checked
                                       class="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span class="text-sm text-slate-700">Filled to full</span>
                            </label>
                            <p class="text-xs text-slate-500">Only full-tank fills produce a consumption figure.</p>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Record</button>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
