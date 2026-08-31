@php
    use App\Enums\PropertyBookingStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canCancel = auth()->user()?->can('cancelAsCustomer', $booking) ?? false;
    $withinWindow = $booking->isWithinFreeCancellation();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Your stay</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $booking->property_name_snapshot }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $booking->reference }}</p>
            </div>
            <a href="{{ route('portal.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Back to My PISFA
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
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

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <span @class([
                    'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                    'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                    'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                    'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                    'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                    'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                ])>{{ $booking->status->label() }}</span>

                @if ($booking->status === PropertyBookingStatus::Pending)
                    <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                        We are confirming this with the property. The rooms are held for you in the meantime, and
                        nothing has been charged.
                    </p>
                @endif

                @if ($booking->closure_reason)
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">
                        <span class="font-bold">Reason:</span> {{ $booking->closure_reason }}
                    </p>
                @endif

                <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Room</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $booking->rooms }} &times; {{ $booking->room_type_name_snapshot }}
                        </dd>
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
                        <dt class="text-sm font-semibold text-slate-600">Arrival and departure</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            In from {{ Str::substr($booking->check_in_from_snapshot, 0, 5) }},
                            out by {{ Str::substr($booking->check_out_by_snapshot, 0, 5) }}
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
                </dl>

                @if ($booking->special_requests)
                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                        <h2 class="text-sm font-bold text-slate-900">What you asked for</h2>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $booking->special_requests }}</p>
                    </div>
                @endif
            </div>

            @if ($checkoutUrl)
                <div class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-black text-slate-900">Payment</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ \App\Support\Money::format($booking->outstandingAmountMinor(), $booking->currency) }} is outstanding.
                    </p>
                    <a href="{{ $checkoutUrl }}" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 text-sm font-bold text-white hover:bg-emerald-900">
                        Pay now
                    </a>
                </div>
            @endif

            @if ($canCancel)
                <form method="POST" action="{{ route('portal.property-bookings.cancel', ['customerPropertyBooking' => $booking->reference]) }}"
                      class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    @csrf
                    <h2 class="text-lg font-black text-slate-900">Cancel this stay</h2>

                    @if ($withinWindow)
                        <p class="mt-2 text-sm text-slate-600">
                            You can still cancel this free of charge
                            @if ($booking->cancellation_cutoff_at)
                                until {{ $booking->cancellation_cutoff_at->timezone($timezone)->format('j M Y, H:i') }}.
                            @else
                                .
                            @endif
                        </p>
                        <div class="mt-4">
                            <label for="cancel-reason" class="block text-sm font-semibold">Why are you cancelling?</label>
                            <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                   placeholder="Our plans changed"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <button type="submit" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                            Cancel my stay
                        </button>
                    @else
                        <p class="mt-2 text-sm text-slate-600">
                            The free-cancellation window has closed. Please
                            <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">contact us</a>
                            and we will see what the property can do.
                        </p>
                    @endif
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
