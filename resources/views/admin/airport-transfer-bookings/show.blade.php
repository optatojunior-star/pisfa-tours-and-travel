@php
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $allowedTransitions = $booking->status->allowedTransitions();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Airport transfer</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $booking->reference }}</h1>
            </div>
            <a href="{{ route('admin.airport-transfer-bookings.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to transfers</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This operation was rejected.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-transfer-summary">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="admin-transfer-summary" class="text-lg font-black text-slate-950">{{ $booking->transfer_type->label() }} · {{ $booking->airport_code_snapshot }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $booking->status->label() }}</span>
                </div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Route</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->airport_name_snapshot }} — {{ $booking->location_name_snapshot }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Service time ({{ $timezone }})</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->service_starts_at->timezone($timezone)->format('j M Y, H:i') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estimated end</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->service_ends_at->timezone($timezone)->format('j M Y, H:i') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Flight</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->flight_scheduled_at->timezone($timezone)->format('j M Y, H:i') }}@if (filled($booking->flight_number)) · {{ $booking->flight_number }}@endif</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Party</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->passenger_count }} pax · {{ $booking->luggage_count }} bags</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Amount</dt><dd class="mt-1 font-bold text-emerald-800">{{ Money::format((int) $booking->amount_minor, $booking->currency) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Requester</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->contact_name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->contact_email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phone</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->contact_phone }}</dd></div>
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Address</dt><dd class="mt-1 font-bold text-slate-900">{{ $booking->service_address }}</dd></div>
                    @if (filled($booking->special_requests))
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Special requests</dt><dd class="mt-1 text-slate-800">{{ $booking->special_requests }}</dd></div>
                    @endif
                    @if (filled($booking->cancellation_reason))
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reason on file</dt><dd class="mt-1 text-slate-800">{{ $booking->cancellation_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-transfer-assignment">
                <h2 id="admin-transfer-assignment" class="text-lg font-black text-slate-950">Vehicle and driver</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Current: {{ $booking->assignedVehicle === null ? 'no vehicle' : trim($booking->assignedVehicle->year.' '.$booking->assignedVehicle->make.' '.$booking->assignedVehicle->model) }}
                    · {{ $booking->assignedDriver?->name ?? 'no driver' }}
                </p>

                @if ($vehicles->isEmpty() || $drivers->isEmpty())
                    <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="note">No available <strong>{{ str($booking->vehicle_type_snapshot)->replace('_', ' ')->title() }}</strong> with room for {{ $booking->passenger_count }} passenger(s) and {{ $booking->luggage_count }} bag(s), or no active verified driver, is on file. Update the fleet or driver accounts first.</p>
                @else
                    <form method="POST" action="{{ route('admin.airport-transfer-bookings.assignment', $booking) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="assign-vehicle" class="block text-sm font-semibold text-slate-800">Vehicle</label>
                            <select id="assign-vehicle" name="vehicle_id" required class="mt-1 block w-full rounded-xl border-slate-300">
                                <option value="">Select vehicle</option>
                                @foreach ($vehicles as $vehicle)
                                    <option value="{{ $vehicle->id }}" @selected((int) old('vehicle_id', $booking->assigned_vehicle_id) === $vehicle->id)>{{ trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model) }} — {{ $vehicle->seating_capacity }} seats</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('vehicle_id')" class="mt-1" />
                        </div>
                        <div>
                            <label for="assign-driver" class="block text-sm font-semibold text-slate-800">Driver</label>
                            <select id="assign-driver" name="driver_user_id" required class="mt-1 block w-full rounded-xl border-slate-300">
                                <option value="">Select driver</option>
                                @foreach ($drivers as $driver)
                                    <option value="{{ $driver->id }}" @selected((int) old('driver_user_id', $booking->assigned_driver_user_id) === $driver->id)>{{ $driver->name }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('driver_user_id')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="assign-reason" class="block text-sm font-semibold text-slate-800">Replacement reason <span class="font-normal text-slate-500">(required when changing an existing team)</span></label>
                            <input id="assign-reason" name="replacement_reason" type="text" minlength="5" maxlength="500" value="{{ old('replacement_reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('replacement_reason')" class="mt-1" />
                            <x-input-error :messages="$errors->get('assignment')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Save assignment</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-transfer-status">
                <h2 id="admin-transfer-status" class="text-lg font-black text-slate-950">Status</h2>
                @if ($allowedTransitions === [])
                    <p class="mt-2 text-sm text-slate-600">{{ $booking->status->label() }} is a terminal status. No further transitions are permitted.</p>
                @else
                    <form method="POST" action="{{ route('admin.airport-transfer-bookings.transition', $booking) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="transition-status" class="block text-sm font-semibold text-slate-800">Move to</label>
                            <select id="transition-status" name="status" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($allowedTransitions as $next)
                                    <option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('status')" class="mt-1" />
                        </div>
                        <div>
                            <label for="transition-reason" class="block text-sm font-semibold text-slate-800">Reason <span class="font-normal text-slate-500">(required to cancel or decline)</span></label>
                            <input id="transition-reason" name="reason" type="text" minlength="5" maxlength="1000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Apply status change</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-transfer-reschedule">
                <h2 id="admin-transfer-reschedule" class="text-lg font-black text-slate-950">Reschedule</h2>
                <p class="mt-2 text-sm text-slate-600">Rescheduling re-checks the driver and vehicle against tours, car hire, and other transfers before saving.</p>
                <form method="POST" action="{{ route('admin.airport-transfer-bookings.reschedule', $booking) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="reschedule-service" class="block text-sm font-semibold text-slate-800">New service time ({{ $timezone }})</label>
                        <input id="reschedule-service" name="service_starts_at" type="datetime-local" required value="{{ old('service_starts_at', $booking->service_starts_at->timezone($timezone)->format('Y-m-d\TH:i')) }}" class="mt-1 block w-full rounded-xl border-slate-300">
                        <x-input-error :messages="$errors->get('service_starts_at')" class="mt-1" />
                    </div>
                    <div>
                        <label for="reschedule-flight" class="block text-sm font-semibold text-slate-800">New flight time <span class="font-normal text-slate-500">(optional)</span></label>
                        <input id="reschedule-flight" name="flight_scheduled_at" type="datetime-local" value="{{ old('flight_scheduled_at') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                        <x-input-error :messages="$errors->get('flight_scheduled_at')" class="mt-1" />
                    </div>
                    <div>
                        <label for="reschedule-flight-number" class="block text-sm font-semibold text-slate-800">Flight number</label>
                        <input id="reschedule-flight-number" name="flight_number" type="text" maxlength="32" value="{{ old('flight_number', $booking->flight_number) }}" class="mt-1 block w-full rounded-xl border-slate-300">
                        <x-input-error :messages="$errors->get('flight_number')" class="mt-1" />
                    </div>
                    <div>
                        <label for="reschedule-reason" class="block text-sm font-semibold text-slate-800">Reason</label>
                        <input id="reschedule-reason" name="reason" type="text" required minlength="5" maxlength="1000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                        <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Reschedule transfer</button>
                    </div>
                </form>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-transfer-history">
                <h2 id="admin-transfer-history" class="text-lg font-black text-slate-950">Assignment history</h2>
                @if ($booking->assignments->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">No vehicle or driver has been assigned to this transfer yet.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($booking->assignments as $assignment)
                            <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                <p class="font-semibold text-slate-900">{{ $assignment->driver?->name ?? 'Removed driver' }} · {{ $assignment->vehicle === null ? 'Removed vehicle' : trim($assignment->vehicle->make.' '.$assignment->vehicle->model) }}</p>
                                <p class="mt-1 text-xs text-slate-600">Assigned {{ $assignment->assigned_at->timezone($timezone)->format('j M Y, H:i') }} by {{ $assignment->assignedBy?->name ?? 'system' }}</p>
                                @if ($assignment->unassigned_at !== null)
                                    <p class="mt-1 text-xs text-slate-600">Released {{ $assignment->unassigned_at->timezone($timezone)->format('j M Y, H:i') }} by {{ $assignment->unassignedBy?->name ?? 'system' }} — {{ $assignment->unassignment_reason }}</p>
                                @else
                                    <p class="mt-1 text-xs font-bold text-emerald-700">Active assignment</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
