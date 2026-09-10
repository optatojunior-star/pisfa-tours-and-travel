@php
    use App\Support\WhatsApp;

    /** @var \App\Models\AirportTransferBooking $booking */
    $driver = $booking->assignedDriver;
    $vehicle = $booking->assignedVehicle;
    $photograph = $driver?->driverProfile?->photographUrl();
    $phone = filled($driver?->phone) ? (string) $driver->phone : null;

    // tel: wants no spaces or punctuation; the displayed number keeps its shape.
    $dialable = $phone === null ? null : preg_replace('/[^0-9+]/', '', $phone);
@endphp

{{--
    Who is meeting you, and what they are driving.

    The confirmation used to give a name and, if the office had recorded one, a
    phone number as plain text. At Entebbe arrivals that answers the question
    only after a stranger has already started talking to you — and "where is my
    driver" is the call this company takes most often.

    A face, a plate and a number you can press are the three things that turn
    that call into no call at all.
--}}
<section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="transfer-team">
    <h2 id="transfer-team" class="text-lg font-black text-slate-950">Who is meeting you</h2>

    @if ($driver === null && $vehicle === null)
        <p class="mt-3 text-sm leading-6 text-slate-600">
            A vehicle and driver are assigned once our team confirms the transfer. You will be emailed the
            details, and they appear here.
        </p>
    @else
        <div class="mt-5 flex flex-col gap-5 sm:flex-row sm:items-start">
            <div class="shrink-0">
                @if ($photograph)
                    <img src="{{ $photograph }}"
                         alt="{{ $driver?->name ? 'Photograph of '.$driver->name : 'Your driver' }}"
                         class="size-28 rounded-2xl border border-slate-200 object-cover">
                @else
                    {{-- Initials rather than a stock silhouette: it is honest
                         about there being no photograph, and still gives the eye
                         something to match against the name. --}}
                    <div class="grid size-28 place-items-center rounded-2xl bg-emerald-50 text-3xl font-black text-emerald-800"
                         aria-hidden="true">
                        {{ $driver === null ? '?' : str($driver->name)->trim()->substr(0, 1)->upper() }}
                    </div>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Driver</dt>
                        <dd class="mt-1 text-base font-bold text-slate-900">{{ $driver?->name ?? 'Being assigned' }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicle</dt>
                        <dd class="mt-1 text-base font-bold text-slate-900">
                            {{ $vehicle === null ? 'Being assigned' : trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model) }}
                        </dd>
                    </div>

                    {{-- The plate is the thing you actually check in a car park,
                         and it was never shown. --}}
                    @if ($vehicle?->registration_plate)
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Number plate</dt>
                            <dd class="mt-1 inline-block rounded-lg border-2 border-slate-900 bg-white px-3 py-1 font-mono text-lg font-black tracking-widest text-slate-900">
                                {{ $vehicle->registration_plate }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($dialable !== null)
                    <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                        <a href="tel:{{ $dialable }}"
                           class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-800 px-4 text-sm font-bold text-white hover:bg-emerald-900">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.68 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.32 1.85.55 2.81.68A2 2 0 0 1 22 16.92z"/>
                            </svg>
                            Call {{ $phone }}
                        </a>

                        @if (WhatsApp::isConfigured())
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $dialable) }}"
                               target="_blank" rel="noopener noreferrer"
                               class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-[#25D366] px-4 text-sm font-bold text-[#128C4A] hover:bg-[#25D366]/10">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 0 1 6.988 2.896 9.83 9.83 0 0 1 2.893 6.994c-.003 5.45-4.437 9.885-9.885 9.885m8.413-18.297A11.8 11.8 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.9 11.9 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.82 11.82 0 0 0-3.48-8.413" />
                                </svg>
                                WhatsApp the driver
                            </a>
                        @endif
                    </div>
                @elseif ($driver !== null)
                    <p class="mt-5 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                        We do not have a phone number on file for your driver.
                        Call the PISFA office on {{ config('pisfa.company.phone', 'our main line') }} if you need them.
                    </p>
                @endif

                @if ($booking->transfer_type->airportIsOrigin())
                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        For an airport pickup your driver waits in the arrivals hall with a name board.
                        If your flight is delayed we follow it — there is no need to call ahead.
                    </p>
                @endif
            </div>
        </div>
    @endif
</section>
