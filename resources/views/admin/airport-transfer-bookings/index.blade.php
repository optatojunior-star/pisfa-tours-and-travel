@php
    use App\Enums\AirportTransferBookingStatus;
    use App\Enums\AirportTransferType;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Airport transfers</h1>
            </div>
            <a href="{{ route('admin.airport-transfer-settings.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Airports, locations &amp; rates</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="transfer-admin-filters">
                <h2 id="transfer-admin-filters" class="sr-only">Filter airport transfers</h2>
                <form method="GET" action="{{ route('admin.airport-transfer-bookings.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="admin-transfer-q" class="block text-sm font-semibold">Search</label>
                        <input id="admin-transfer-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, contact, airport" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="admin-transfer-status" class="block text-sm font-semibold">Status</label>
                        <select id="admin-transfer-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (AirportTransferBookingStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-transfer-type" class="block text-sm font-semibold">Direction</label>
                        <select id="admin-transfer-type" name="transfer_type" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Both directions</option>
                            @foreach (AirportTransferType::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['transfer_type'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-transfer-airport" class="block text-sm font-semibold">Airport</label>
                        <select id="admin-transfer-airport" name="airport_id" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All airports</option>
                            @foreach ($airports as $airport)
                                <option value="{{ $airport->id }}" @selected((int) ($filters['airport_id'] ?? 0) === $airport->id)>{{ $airport->code }} — {{ $airport->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-transfer-location" class="block text-sm font-semibold">Location</label>
                        <select id="admin-transfer-location" name="airport_transfer_location_id" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All locations</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected((int) ($filters['airport_transfer_location_id'] ?? 0) === $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-transfer-assignment" class="block text-sm font-semibold">Assignment</label>
                        <select id="admin-transfer-assignment" name="assignment" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Any</option>
                            <option value="assigned" @selected(($filters['assignment'] ?? '') === 'assigned')>Vehicle and driver set</option>
                            <option value="unassigned" @selected(($filters['assignment'] ?? '') === 'unassigned')>Needs assignment</option>
                        </select>
                    </div>
                    <div>
                        <label for="admin-transfer-from" class="block text-sm font-semibold">Service from</label>
                        <input id="admin-transfer-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="admin-transfer-to" class="block text-sm font-semibold">Service to</label>
                        <input id="admin-transfer-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('admin.airport-transfer-bookings.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No airport transfers match these filters</h2>
                    <p class="mt-2 text-sm text-slate-600">Adjust the search, or publish transfer rates so customers can request a route.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Airport transfer requests</caption>
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Route</th>
                                <th scope="col" class="px-4 py-3">Service time</th>
                                <th scope="col" class="px-4 py-3">Requester</th>
                                <th scope="col" class="px-4 py-3">Team</th>
                                <th scope="col" class="px-4 py-3">Amount</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($bookings as $booking)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-emerald-700">{{ $booking->reference }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $booking->airport_code_snapshot }} · {{ $booking->location_name_snapshot }}</span>
                                        <span class="block text-xs text-slate-500">{{ $booking->transfer_type->label() }} · {{ str($booking->vehicle_type_snapshot)->replace('_', ' ')->title() }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">{{ $booking->service_starts_at->timezone($timezone)->format('j M Y, H:i') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $booking->contact_name }}</span>
                                        <span class="block text-xs text-slate-500">{{ $booking->isGuest() ? 'Guest request' : 'Customer account' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        @if ($booking->assignedDriver === null || $booking->assignedVehicle === null)
                                            <span class="rounded-full bg-amber-100 px-2 py-1 font-bold text-amber-900">Needs assignment</span>
                                        @else
                                            <span class="block font-semibold text-slate-900">{{ $booking->assignedDriver->name }}</span>
                                            <span class="block text-slate-500">{{ trim($booking->assignedVehicle->make.' '.$booking->assignedVehicle->model) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap font-semibold">{{ Money::format((int) $booking->amount_minor, $booking->currency) }}</td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $booking->status->label() }}</span></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.airport-transfer-bookings.show', $booking) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-3 text-xs font-bold text-emerald-800">Manage</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $bookings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
