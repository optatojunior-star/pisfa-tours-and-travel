<x-app-layout>
    @php
        $statusValue = $booking->status->value;
        $nextStatuses = $booking->status->allowedTransitions();
        $activeAssignment = $booking->assignments->first(fn ($assignment) => $assignment->unassigned_at === null);
    @endphp

    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><a href="{{ route('admin.tour-bookings.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4">Back to tour bookings</a><div class="mt-4 flex flex-wrap items-center gap-2"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $booking->reference }}</p><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-amber-50 text-amber-800' => $statusValue === 'pending', 'bg-emerald-50 text-emerald-800' => $statusValue === 'confirmed', 'bg-sky-50 text-sky-800' => in_array($statusValue, ['in_progress','completed'], true), 'bg-rose-50 text-rose-800' => $statusValue === 'cancelled'])>{{ $booking->status->label() }}</span></div><h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $booking->package_name_snapshot }}</h1><p class="mt-1 text-sm text-slate-500">{{ $booking->contact_name }} · {{ $booking->traveler_count }} {{ str('traveler')->plural($booking->traveler_count) }}</p></div>
            <div class="flex flex-wrap gap-2">
                @foreach ($nextStatuses as $nextStatus)
                    @if ($nextStatus !== \App\Enums\TourBookingStatus::Cancelled)
                        @php
                            $transitionAvailableAt = match ($nextStatus) {
                                \App\Enums\TourBookingStatus::InProgress => $booking->departure_starts_at_snapshot,
                                \App\Enums\TourBookingStatus::Completed => $booking->departure_ends_at_snapshot,
                                default => null,
                            };
                            $departureStatus = $booking->departure?->status?->value ?? $booking->departure?->status;
                            $transitionBlockedByTime = match ($nextStatus) {
                                \App\Enums\TourBookingStatus::Confirmed => $departureStatus !== 'scheduled'
                                    || ! $booking->departure_starts_at_snapshot?->isFuture(),
                                \App\Enums\TourBookingStatus::InProgress,
                                \App\Enums\TourBookingStatus::Completed => ! $transitionAvailableAt
                                    || $transitionAvailableAt->isFuture(),
                                default => false,
                            };
                            $transitionLabel = match ($nextStatus) {
                                \App\Enums\TourBookingStatus::Confirmed => 'Confirm booking',
                                \App\Enums\TourBookingStatus::InProgress => 'Start tour',
                                \App\Enums\TourBookingStatus::Completed => 'Mark completed',
                                default => $nextStatus->label(),
                            };
                        @endphp
                        @if ($transitionBlockedByTime)
                            <div class="max-w-48 text-right"><button type="button" disabled class="min-h-11 cursor-not-allowed rounded-xl bg-slate-200 px-4 py-2.5 text-sm font-bold text-slate-500">{{ $transitionLabel }}</button><p class="mt-1 text-xs leading-4 text-slate-500">{{ $nextStatus === \App\Enums\TourBookingStatus::Confirmed ? 'Confirmation requires a future scheduled departure.' : ($transitionAvailableAt ? 'Available after '.$transitionAvailableAt->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A').'.' : 'The departure time is unavailable.') }}</p></div>
                        @else
                            <form method="POST" action="{{ route('admin.tour-bookings.transition', ['tourBooking' => $booking]) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $nextStatus->value }}"><button type="submit" class="min-h-11 rounded-xl px-4 py-2.5 text-sm font-bold text-white {{ $nextStatus === \App\Enums\TourBookingStatus::Confirmed ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-sky-700 hover:bg-sky-800' }}">{{ $transitionLabel }}</button></form>
                        @endif
                    @endif
                @endforeach
            </div>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>@endif
            @if ($errors->any())<div id="staff-booking-errors" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1"><p class="font-bold">The booking was not changed.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem] xl:items-start">
                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="staff-trip-heading">
                        <h2 id="staff-trip-heading" class="text-xl font-black text-emerald-950">Trip and customer</h2>
                        <dl class="mt-6 grid gap-5 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Customer</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->customer->name }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 break-all font-semibold text-slate-900"><a href="mailto:{{ $booking->contact_email }}" class="text-emerald-800 underline">{{ $booking->contact_email }}</a></dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Telephone</dt><dd class="mt-1 font-semibold text-slate-900"><a href="tel:{{ $booking->contact_phone }}" class="text-emerald-800 underline">{{ $booking->contact_phone }}</a></dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Destination</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->destination_snapshot ?: 'Not specified' }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Starts</dt><dd class="mt-1 font-semibold text-slate-900"><time datetime="{{ $booking->departure_starts_at_snapshot->toIso8601String() }}">{{ $booking->departure_starts_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ends</dt><dd class="mt-1 font-semibold text-slate-900"><time datetime="{{ $booking->departure_ends_at_snapshot->toIso8601String() }}">{{ $booking->departure_ends_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                            @if ($booking->departure?->meeting_point)<div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Meeting point</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->departure->meeting_point }}</dd></div>@endif
                        </dl>
                        @if ($booking->special_requests)<div class="mt-6 rounded-2xl bg-amber-50 p-4"><h3 class="text-sm font-bold text-amber-950">Customer requests</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-amber-950">{{ $booking->special_requests }}</p></div>@endif
                        @if ($booking->internal_notes)<div class="mt-4 rounded-2xl bg-slate-100 p-4"><h3 class="text-sm font-bold text-slate-900">Internal notes</h3><p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $booking->internal_notes }}</p></div>@endif
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="staff-travelers-heading">
                        <h2 id="staff-travelers-heading" class="text-xl font-black text-emerald-950">Travelers</h2>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            @foreach ($booking->travelers as $traveler)
                                <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4"><div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-slate-900">{{ $traveler->full_name }}</h3>@if ($traveler->is_lead)<span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">Lead</span>@endif<span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-slate-700">{{ $traveler->traveler_type->label() }}</span></div><dl class="mt-3 space-y-2 text-xs text-slate-600">@if ($traveler->date_of_birth)<div><dt class="inline font-bold">Born:</dt> <dd class="inline">{{ $traveler->date_of_birth->format('j M Y') }}</dd></div>@endif @if ($traveler->nationality)<div><dt class="inline font-bold">Nationality:</dt> <dd class="inline">{{ $traveler->nationality }}</dd></div>@endif @if ($traveler->dietary_notes)<div><dt class="font-bold">Dietary notes</dt><dd class="mt-0.5">{{ $traveler->dietary_notes }}</dd></div>@endif @if ($traveler->accessibility_notes)<div><dt class="font-bold">Accessibility notes</dt><dd class="mt-0.5">{{ $traveler->accessibility_notes }}</dd></div>@endif</dl></article>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="assignment-history-heading">
                        <h2 id="assignment-history-heading" class="text-xl font-black text-emerald-950">Assignment history</h2>
                        @if ($booking->assignments->isEmpty())<p class="mt-4 text-sm text-slate-600">No driver has been assigned to this booking.</p>@else
                            <div class="mt-5 overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-left text-sm"><caption class="sr-only">Driver assignment history</caption><thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th scope="col" class="py-2 pr-4">Driver</th><th scope="col" class="px-4 py-2">Period</th><th scope="col" class="px-4 py-2">Assigned</th><th scope="col" class="py-2 pl-4">State</th></tr></thead><tbody class="divide-y divide-slate-200">@foreach ($booking->assignments as $assignment)<tr><td class="py-3 pr-4 font-semibold text-slate-900">{{ $assignment->driver?->name ?? 'Removed account' }}</td><td class="px-4 py-3 text-xs text-slate-600">{{ $assignment->starts_at->timezone(config('pisfa.business_timezone'))->format('j M, g:i A') }} – {{ $assignment->ends_at->timezone(config('pisfa.business_timezone'))->format('j M, g:i A') }}</td><td class="px-4 py-3 text-xs text-slate-600">{{ $assignment->assigned_at->timezone(config('pisfa.business_timezone'))->format('j M Y') }}@if ($assignment->assignedBy)<br>by {{ $assignment->assignedBy->name }}@endif</td><td class="py-3 pl-4">@if ($assignment->unassigned_at)<span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">Ended</span>@else<span class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">Active</span>@endif @if ($assignment->unassignment_reason)<p class="mt-1 max-w-56 text-xs text-slate-500">{{ $assignment->unassignment_reason }}</p>@endif</td></tr>@endforeach</tbody></table></div>
                        @endif
                    </section>
                </div>

                <aside class="space-y-6 xl:sticky xl:top-24">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="booking-price-heading"><h2 id="booking-price-heading" class="text-lg font-black text-emerald-950">Price snapshot</h2><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-3"><dt class="text-slate-600">Per traveler</dt><dd class="font-semibold">{{ \App\Support\Money::format((int) $booking->unit_price_minor, $booking->currency) }}</dd></div><div class="flex justify-between gap-3"><dt class="text-slate-600">Travelers</dt><dd class="font-semibold">{{ $booking->traveler_count }}</dd></div><div class="flex justify-between gap-3 border-t border-slate-200 pt-3"><dt class="font-bold">Total</dt><dd class="text-lg font-black text-amber-700">{{ \App\Support\Money::format((int) $booking->total_minor, $booking->currency) }}</dd></div></dl><div class="mt-5 rounded-xl bg-emerald-50 p-3 text-xs leading-5 text-emerald-950"><strong>No online payment was collected.</strong> This F03 screen must not record or imply a provider payment.</div></section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="driver-assignment-heading">
                        <h2 id="driver-assignment-heading" class="text-lg font-black text-emerald-950">Driver assignment</h2>
                        @if (in_array($statusValue, ['confirmed', 'in_progress'], true) && $booking->departure_ends_at_snapshot->isFuture())
                            <p class="mt-2 text-sm leading-6 text-slate-600">Only active eligible drivers are listed. Overlapping work is rejected by the server.</p>
                            <form method="POST" action="{{ route('admin.tour-bookings.assign', ['tourBooking' => $booking]) }}" class="mt-5 space-y-4">@csrf @method('PATCH')<div><label for="driver-user" class="block text-sm font-semibold text-slate-800">Assigned driver</label><select id="driver-user" name="driver_user_id" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"><option value="">Unassigned</option>@foreach ($drivers as $driver)<option value="{{ $driver->id }}" @selected((string) old('driver_user_id', $booking->assigned_driver_user_id) === (string) $driver->id)>{{ $driver->name }}{{ $driver->phone ? ' · '.$driver->phone : '' }}</option>@endforeach</select></div><div><label for="assignment-reason" class="block text-sm font-semibold text-slate-800">Assignment note <span class="font-normal text-slate-500">(required when removing a driver)</span></label><textarea id="assignment-reason" name="reason" rows="3" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600" placeholder="Add context for a reassignment, or the required reason when removing a driver.">{{ old('reason') }}</textarea></div><button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Save assignment</button></form>
                        @elseif (in_array($statusValue, ['confirmed', 'in_progress'], true) && $booking->assigned_driver_user_id)
                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm leading-6 text-amber-900">This tour has ended, so another driver cannot be assigned. You can release the current driver while the booking is finalized.</p>
                            <form method="POST" action="{{ route('admin.tour-bookings.assign', ['tourBooking' => $booking]) }}" class="mt-4 space-y-3">@csrf @method('PATCH')<input type="hidden" name="driver_user_id" value=""><div><label for="ended-assignment-reason" class="block text-sm font-semibold text-slate-800">Release reason</label><textarea id="ended-assignment-reason" name="reason" required rows="3" maxlength="500" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></textarea></div><button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Release driver</button></form>
                        @elseif (in_array($statusValue, ['confirmed', 'in_progress'], true))
                            <p class="mt-3 rounded-xl bg-slate-100 p-3 text-sm leading-6 text-slate-600">This tour has ended. New driver assignments are no longer available.</p>
                        @elseif ($statusValue === 'pending')
                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm leading-6 text-amber-900">Confirm this booking before assigning a driver.</p>
                        @else
                            <p class="mt-3 rounded-xl bg-slate-100 p-3 text-sm leading-6 text-slate-600">Driver assignments are locked after a booking is completed or cancelled.</p>
                        @endif
                    </section>

                    @if (collect($nextStatuses)->contains(\App\Enums\TourBookingStatus::Cancelled))
                        <section class="rounded-3xl border border-rose-200 bg-white p-5 shadow-sm" aria-labelledby="staff-cancel-booking-heading"><h2 id="staff-cancel-booking-heading" class="text-lg font-black text-rose-900">Cancel booking</h2><p class="mt-2 text-sm leading-6 text-slate-600">Cancellation releases capacity and is retained in the audit history.</p><form method="POST" action="{{ route('admin.tour-bookings.transition', ['tourBooking' => $booking]) }}" class="mt-4 space-y-4">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><div><label for="staff-cancellation-reason" class="block text-sm font-semibold text-rose-900">Reason</label><textarea id="staff-cancellation-reason" name="reason" required maxlength="500" rows="3" class="mt-1 block w-full rounded-xl border-rose-300 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea></div><button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-800">Cancel booking</button></form></section>
                    @endif
                </aside>
            </div>
        </div>
    </div>
    @if ($errors->any())@push('scripts')<script>document.getElementById('staff-booking-errors')?.focus();</script>@endpush @endif
</x-app-layout>
