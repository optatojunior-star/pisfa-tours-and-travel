@php
    use App\Enums\VehicleOperationalStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $expired = collect($expiring)->filter(fn (array $row): bool => $row['has_expired']);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Fleet</h1>
            </div>
            <a href="{{ route('admin.vehicles.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Vehicle catalogue</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($expired->isNotEmpty())
                <div class="rounded-3xl border border-rose-300 bg-rose-50 p-6" role="alert">
                    <h2 class="text-lg font-black text-rose-900">{{ $expired->count() }} vehicle document(s) have expired</h2>
                    <p class="mt-2 text-sm text-rose-800">
                        A vehicle without valid cover should not be on the road. Renew these before dispatching.
                    </p>
                </div>
            @endif

            <section class="grid gap-4 sm:grid-cols-3" aria-label="Fleet at a glance">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicles</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $counts['total'] }}</p>
                </div>
                <div @class([
                    'rounded-2xl border p-5',
                    'border-amber-300 bg-amber-50' => $counts['off_road'] > 0,
                    'border-slate-200 bg-white' => $counts['off_road'] === 0,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Off the road</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $counts['off_road'] }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open jobs</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $counts['open_jobs'] }}</p>
                </div>
            </section>

            @if ($dueRecords->isNotEmpty())
                <section aria-labelledby="due-heading">
                    <h2 id="due-heading" class="text-lg font-black text-slate-950">Service due</h2>
                    <p class="mt-1 text-sm text-slate-600">By date or by odometer, whichever came first.</p>

                    <ul class="mt-4 space-y-3">
                        @foreach ($dueRecords as $record)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                                <div>
                                    <p class="font-bold text-slate-900">
                                        {{ $record->vehicle?->make }} {{ $record->vehicle?->model }}
                                        <span class="ml-1 font-mono text-xs text-slate-600">{{ $record->vehicle?->registration_plate }}</span>
                                    </p>
                                    <p class="mt-1 text-sm text-slate-700">{{ $record->type->label() }} {{ $record->dueReason($now) }}</p>
                                </div>
                                @if ($record->vehicle)
                                    <a href="{{ route('admin.fleet.show', $record->vehicle) }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Open</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($expiring !== [])
                <section aria-labelledby="expiry-heading">
                    <h2 id="expiry-heading" class="text-lg font-black text-slate-950">Paperwork</h2>
                    <p class="mt-1 text-sm text-slate-600">Insurance and registration expiring within {{ $noticeDays }} days.</p>

                    <div class="mt-4 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Expiring vehicle documents</caption>
                            <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="px-4 py-3">Vehicle</th>
                                    <th scope="col" class="px-4 py-3">Document</th>
                                    <th scope="col" class="px-4 py-3">Expires</th>
                                    <th scope="col" class="px-4 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($expiring as $row)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-slate-800">{{ $row['vehicle']?->make }} {{ $row['vehicle']?->model }}</p>
                                            <p class="font-mono text-xs text-slate-500">{{ $row['vehicle']?->registration_plate }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">{{ $row['document']->category->label() }}</td>
                                        <td class="px-4 py-3 text-slate-700">{{ $row['expires_at']?->format('j M Y') ?? '—' }}</td>
                                        <td class="px-4 py-3">
                                            <span @class([
                                                'rounded-full px-3 py-1 text-xs font-bold',
                                                'bg-rose-100 text-rose-900' => $row['has_expired'],
                                                'bg-amber-50 text-amber-900' => ! $row['has_expired'],
                                            ])>{{ $row['has_expired'] ? 'Expired' : 'Expiring' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section aria-labelledby="vehicles-heading">
                <h2 id="vehicles-heading" class="text-lg font-black text-slate-950">Vehicles</h2>
                <p class="mt-1 text-sm text-slate-600">Utilisation over the last {{ $utilisationDays }} days.</p>

                @if ($vehicles->isEmpty())
                    <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                        <p class="text-sm text-slate-600">No vehicles are registered yet.</p>
                        <a href="{{ route('admin.vehicles.create') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white">Add a vehicle</a>
                    </div>
                @else
                    <div class="mt-4 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Fleet vehicles</caption>
                            <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th scope="col" class="px-4 py-3">Vehicle</th>
                                    <th scope="col" class="px-4 py-3">Status</th>
                                    <th scope="col" class="px-4 py-3 text-right">Odometer</th>
                                    <th scope="col" class="px-4 py-3 text-right">Open jobs</th>
                                    <th scope="col" class="px-4 py-3">Utilisation</th>
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($vehicles as $item)
                                    @php $use = $utilisation[$item->id] ?? null; @endphp
                                    <tr>
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-slate-800">{{ $item->make }} {{ $item->model }}</p>
                                            <p class="font-mono text-xs text-slate-500">{{ $item->registration_plate }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span @class([
                                                'rounded-full px-3 py-1 text-xs font-bold',
                                                'bg-emerald-50 text-emerald-800' => $item->operational_status === VehicleOperationalStatus::Available,
                                                'bg-amber-50 text-amber-900' => $item->operational_status === VehicleOperationalStatus::Maintenance,
                                                'bg-slate-100 text-slate-700' => $item->operational_status === VehicleOperationalStatus::Unavailable,
                                                'bg-rose-50 text-rose-800' => $item->operational_status === VehicleOperationalStatus::Retired,
                                            ])>{{ $item->operational_status->label() }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $item->formattedOdometer() }}</td>
                                        <td @class([
                                            'px-4 py-3 text-right tabular-nums font-semibold',
                                            'text-amber-800' => $item->open_maintenance_count > 0,
                                            'text-slate-400' => $item->open_maintenance_count === 0,
                                        ])>{{ $item->open_maintenance_count }}</td>
                                        <td class="px-4 py-3">
                                            @if ($use)
                                                <div class="flex items-center gap-2">
                                                    <span class="h-2 w-24 overflow-hidden rounded-full bg-slate-100">
                                                        <span class="block h-full rounded-full bg-emerald-500" style="width: {{ $use['utilisation_percent'] }}%"></span>
                                                    </span>
                                                    <span class="text-xs tabular-nums text-slate-600">{{ $use['utilisation_percent'] }}%</span>
                                                </div>
                                                <p class="mt-1 text-xs text-slate-500">{{ $use['days_hired'] }} days, {{ $use['bookings'] }} {{ str('booking')->plural($use['bookings']) }}</p>
                                            @else
                                                <span class="text-xs text-slate-400">Not hired in this window</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.fleet.show', $item) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
