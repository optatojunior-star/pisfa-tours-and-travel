<x-app-layout>
    @php
        $statusValue = $booking->status?->value ?? $booking->status;
        $statusLabel = $booking->status instanceof \App\Enums\TourBookingStatus ? $booking->status->label() : str($statusValue)->headline();
    @endphp

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('portal.bookings.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4 hover:text-emerald-950">Back to my bookings</a>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $booking->package_name_snapshot }}</h1>
                <p class="mt-1 font-mono text-xs font-semibold text-emerald-700">{{ $booking->reference }}</p>
            </div>
            <span @class([
                'inline-flex w-fit rounded-full px-3 py-1.5 text-sm font-bold',
                'bg-amber-50 text-amber-800' => $statusValue === 'pending',
                'bg-emerald-50 text-emerald-800' => $statusValue === 'confirmed',
                'bg-sky-50 text-sky-800' => in_array($statusValue, ['in_progress', 'completed'], true),
                'bg-rose-50 text-rose-800' => $statusValue === 'cancelled',
            ])>{{ $statusLabel }}</span>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>
            @endif
            @if ($errors->any())
                <div id="booking-detail-errors" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1">
                    <p class="font-bold">The booking was not changed.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="trip-heading">
                        <h2 id="trip-heading" class="text-xl font-black text-emerald-950">Trip details</h2>
                        <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Destination</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->destination_snapshot ?: 'Not specified' }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Travelers</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->traveler_count }}</dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Starts</dt><dd class="mt-1 font-semibold text-slate-900"><time datetime="{{ $booking->departure_starts_at_snapshot->toIso8601String() }}">{{ $booking->departure_starts_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                            <div><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Ends</dt><dd class="mt-1 font-semibold text-slate-900"><time datetime="{{ $booking->departure_ends_at_snapshot->toIso8601String() }}">{{ $booking->departure_ends_at_snapshot->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></dd></div>
                            @if ($booking->departure?->meeting_point)<div class="sm:col-span-2"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Meeting point</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->departure->meeting_point }}</dd></div>@endif
                        </dl>
                    </section>

        <x-pay-now :payable="$booking" />

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="travelers-list-heading">
                        <h2 id="travelers-list-heading" class="text-xl font-black text-emerald-950">Travelers</h2>
                        <div class="mt-5 divide-y divide-slate-200">
                            @foreach ($booking->travelers->sortBy('sort_order') as $traveler)
                                <article class="py-4 first:pt-0 last:pb-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-bold text-slate-900">{{ $traveler->full_name }}</h3>
                                        @if ($traveler->is_lead)<span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-800">Lead</span>@endif
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ $traveler->traveler_type?->label() ?? str($traveler->traveler_type)->headline() }}</span>
                                    </div>
                                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                        @if ($traveler->date_of_birth)<div><dt class="text-xs text-slate-500">Date of birth</dt><dd class="mt-0.5">{{ $traveler->date_of_birth->format('j M Y') }}</dd></div>@endif
                                        @if ($traveler->nationality)<div><dt class="text-xs text-slate-500">Nationality</dt><dd class="mt-0.5">{{ $traveler->nationality }}</dd></div>@endif
                                        @if ($traveler->dietary_notes)<div><dt class="text-xs text-slate-500">Dietary notes</dt><dd class="mt-0.5">{{ $traveler->dietary_notes }}</dd></div>@endif
                                        @if ($traveler->accessibility_notes)<div class="sm:col-span-3"><dt class="text-xs text-slate-500">Accessibility notes</dt><dd class="mt-0.5">{{ $traveler->accessibility_notes }}</dd></div>@endif
                                    </dl>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="contact-summary-heading">
                        <h2 id="contact-summary-heading" class="text-xl font-black text-emerald-950">Contact and requests</h2>
                        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-slate-500">Contact name</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->contact_name }}</dd></div>
                            <div><dt class="text-slate-500">Telephone</dt><dd class="mt-1 font-semibold text-slate-900">{{ $booking->contact_phone }}</dd></div>
                            <div class="sm:col-span-2"><dt class="text-slate-500">Email</dt><dd class="mt-1 break-all font-semibold text-slate-900">{{ $booking->contact_email }}</dd></div>
                            @if ($booking->special_requests)<div class="sm:col-span-2"><dt class="text-slate-500">Special requests</dt><dd class="mt-1 whitespace-pre-line text-slate-800">{{ $booking->special_requests }}</dd></div>@endif
                        </dl>
                    </section>
                </div>

                <aside class="space-y-6 lg:sticky lg:top-24">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="price-heading">
                        <h2 id="price-heading" class="text-lg font-black text-emerald-950">Price summary</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-600">Per traveler</dt><dd class="font-semibold">{{ \App\Support\Money::format((int) $booking->unit_price_minor, $booking->currency) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-600">Travelers</dt><dd class="font-semibold">{{ $booking->traveler_count }}</dd></div>
                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-3"><dt class="font-bold text-slate-900">Total</dt><dd class="text-lg font-black text-amber-700">{{ \App\Support\Money::format((int) $booking->total_minor, $booking->currency) }}</dd></div>
                        </dl>
                        <div class="mt-5 rounded-xl bg-emerald-50 p-3 text-xs leading-5 text-emerald-950"><strong>No online payment was taken.</strong> PISFA will provide verified payment instructions separately after confirmation.</div>
                    </section>

                    @if ($booking->assignedDriver && in_array($statusValue, ['confirmed', 'in_progress'], true))
                        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="support-heading">
                            <h2 id="support-heading" class="text-lg font-black text-emerald-950">Assigned support</h2>
                            <p class="mt-3 font-bold text-slate-900">{{ $booking->assignedDriver->name }}</p>
                            @if ($booking->assignedDriver->phone)<a href="tel:{{ $booking->assignedDriver->phone }}" class="mt-2 block text-sm font-semibold text-emerald-800 underline">{{ $booking->assignedDriver->phone }}</a>@endif
                        </section>
                    @endif

                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="status-timeline-heading">
                        <h2 id="status-timeline-heading" class="text-lg font-black text-emerald-950">Status history</h2>
                        <ol class="mt-4 space-y-4 text-sm">
                            <li class="border-l-2 border-emerald-500 pl-4"><p class="font-bold text-slate-900">Request submitted</p><time datetime="{{ $booking->created_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $booking->created_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></li>
                            @if ($booking->confirmed_at)<li class="border-l-2 border-emerald-500 pl-4"><p class="font-bold text-slate-900">Confirmed</p><time datetime="{{ $booking->confirmed_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $booking->confirmed_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></li>@endif
                            @if ($booking->in_progress_at)<li class="border-l-2 border-sky-500 pl-4"><p class="font-bold text-slate-900">Tour started</p><time datetime="{{ $booking->in_progress_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $booking->in_progress_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></li>@endif
                            @if ($booking->completed_at)<li class="border-l-2 border-sky-500 pl-4"><p class="font-bold text-slate-900">Completed</p><time datetime="{{ $booking->completed_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $booking->completed_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></li>@endif
                            @if ($booking->cancelled_at)<li class="border-l-2 border-rose-500 pl-4"><p class="font-bold text-slate-900">Cancelled</p><time datetime="{{ $booking->cancelled_at->toIso8601String() }}" class="text-xs text-slate-500">{{ $booking->cancelled_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time>@if ($booking->cancellation_reason)<p class="mt-1 text-xs text-slate-600">{{ $booking->cancellation_reason }}</p>@endif</li>@endif
                        </ol>
                    </section>

                    @if ($canCancel)
                        <section x-data="{ open: false }" class="rounded-3xl border border-rose-200 bg-white p-5 shadow-sm" aria-labelledby="cancel-heading">
                            <h2 id="cancel-heading" class="text-lg font-black text-rose-900">Cancel booking</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Cancellation is available until <time datetime="{{ $cancellationDeadline->toIso8601String() }}" class="font-bold">{{ $cancellationDeadline->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time>.</p>
                            <button type="button" @click="open = true" x-show="! open" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" aria-controls="cancel-form">Request cancellation</button>
                            <form id="cancel-form" x-show="open" x-cloak method="POST" action="{{ route('portal.bookings.cancel', ['customerTourBooking' => $booking]) }}" class="mt-4 space-y-4 rounded-2xl bg-rose-50 p-4">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="cancellation-reason" class="block text-sm font-bold text-rose-950">Reason for cancellation</label>
                                    <textarea id="cancellation-reason" name="reason" required minlength="5" maxlength="500" rows="3" class="mt-1 block w-full rounded-xl border-rose-300 focus:border-rose-500 focus:ring-rose-500">{{ old('reason') }}</textarea>
                                </div>
                                <label class="flex items-start gap-2 text-xs leading-5 text-rose-950"><input type="checkbox" name="confirm_cancellation" value="1" required class="mt-1 rounded border-rose-300 text-rose-700 focus:ring-rose-500"><span>I understand this cancels the booking for every traveler.</span></label>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <button type="submit" class="min-h-11 rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-800">Confirm cancellation</button>
                                    <button type="button" @click="open = false" class="min-h-11 rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-white">Keep booking</button>
                                </div>
                            </form>
                        </section>
                    @elseif (! in_array($statusValue, ['cancelled', 'completed'], true))
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">Online cancellation is no longer available. Contact PISFA for help with this booking.</div>
                    @endif
                </aside>
            </div>
        </div>
    </div>

    @if ($errors->any())
        @push('scripts')<script>document.getElementById('booking-detail-errors')?.focus();</script>@endpush
    @endif
</x-app-layout>
