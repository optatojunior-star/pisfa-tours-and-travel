@php
    use App\Enums\PropertyBookingStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canManage = auth()->user()?->can('manage', $booking) ?? false;
    $shortOfRooms = $availableExcludingThis !== null && $availableExcludingThis < $booking->rooms;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Stay</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $booking->contact_name }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $booking->reference }}</p>
            </div>
            <a href="{{ route('admin.accommodation.bookings.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All stays
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                            'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                            'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                            'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                            'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                        ])>{{ $booking->status->label() }}</span>

                        @if ($booking->status === PropertyBookingStatus::Pending && $booking->holdHasExpired())
                            <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                                The hold on these rooms has lapsed, so they are back in the pool. Confirming will
                                re-check availability first.
                            </p>
                        @endif

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Property</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    @if ($booking->property)
                                        <a href="{{ route('admin.accommodation.show', $booking->property) }}" class="text-emerald-800 hover:underline">
                                            {{ $booking->property_name_snapshot }}
                                        </a>
                                    @else
                                        {{ $booking->property_name_snapshot }}
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Room</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->rooms }} &times; {{ $booking->room_type_name_snapshot }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Dates</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->stayLabel() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Guests</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $booking->adults }} {{ Str::plural('adult', $booking->adults) }}@if ($booking->children > 0), {{ $booking->children }} {{ Str::plural('child', $booking->children) }}@endif
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Per night</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->formattedNightlyRate() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Total</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->formattedTotal() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Outstanding</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ \App\Support\Money::format($booking->outstandingAmountMinor(), $booking->currency) }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Free cancellation until</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $booking->cancellation_cutoff_at?->timezone($timezone)->format('j M Y, H:i') ?? 'Not offered' }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2">
                            <p class="text-sm text-slate-700">
                                <span class="font-semibold text-slate-600">Email:</span>
                                <a href="mailto:{{ $booking->contact_email }}" class="text-emerald-800 hover:underline">{{ $booking->contact_email }}</a>
                            </p>
                            <p class="text-sm text-slate-700">
                                <span class="font-semibold text-slate-600">Phone:</span>
                                <a href="tel:{{ $booking->contact_phone }}" class="text-emerald-800 hover:underline">{{ $booking->contact_phone }}</a>
                            </p>
                        </div>

                        @if ($booking->special_requests)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">What the guest asked for</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $booking->special_requests }}</p>
                            </div>
                        @endif

                        @if ($booking->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closed:</span> {{ $booking->closure_reason }}
                            </p>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Room availability</h2>
                        @if ($availableExcludingThis === null)
                            <p class="mt-2 text-sm text-slate-600">The room type has been removed.</p>
                        @elseif ($shortOfRooms)
                            <p class="mt-2 text-sm font-semibold text-rose-800">
                                Only {{ $availableExcludingThis }} {{ Str::plural('room', $availableExcludingThis) }}
                                free for these dates, and this stay needs {{ $booking->rooms }}.
                            </p>
                        @else
                            <p class="mt-2 text-sm font-semibold text-emerald-800">
                                {{ $availableExcludingThis }} {{ Str::plural('room', $availableExcludingThis) }}
                                free for these dates, not counting this stay.
                            </p>
                        @endif
                        <p class="mt-2 text-xs text-slate-500">
                            Counted on the busiest night of the stay, because a guest needs the same room every night.
                        </p>
                    </div>

                    @if ($canManage && in_array(PropertyBookingStatus::Confirmed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.bookings.confirm', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Confirm</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Availability is re-checked as this runs, so a stay whose hold lapsed cannot promise a
                                room somebody else has taken.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Confirm the stay
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(PropertyBookingStatus::CheckedIn, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.bookings.check-in', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Check in</h2>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark checked in
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(PropertyBookingStatus::CheckedOut, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.bookings.check-out', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Check out</h2>
                            <p class="mt-2 text-sm text-slate-600">Frees the rooms and lets the guest leave a review.</p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark checked out
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(PropertyBookingStatus::Declined, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.bookings.decline', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Decline</h2>
                            <p class="mt-2 text-sm text-slate-600">The guest is told the reason, so make it useful.</p>
                            <div class="mt-4">
                                <label for="decline-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="decline-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="The lodge is closed that week"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Decline
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(PropertyBookingStatus::Cancelled, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.bookings.cancel', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Cancel</h2>
                            <p class="mt-2 text-sm text-slate-600">Frees the rooms immediately and tells the guest.</p>
                            <div class="mt-4">
                                <label for="cancel-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Cancel the stay
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                This stay has reached the end of its life and is kept as a record.
                            </p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
