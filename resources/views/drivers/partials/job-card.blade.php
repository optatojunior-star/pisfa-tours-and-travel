@php
    /** @var object $row */
    $trip = $row->trip ?? null;
    $starts = \Carbon\CarbonImmutable::parse($row->starts_at)->timezone($timezone);
    $ends = \Carbon\CarbonImmutable::parse($row->ends_at)->timezone($timezone);
@endphp

<article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $row->assignment_source->label() }}</p>
            <h3 class="mt-1 text-lg font-black text-slate-950">{{ $row->summary }}</h3>
            <p class="mt-1 font-mono text-xs text-slate-500">{{ $row->booking_reference }}</p>
        </div>
        @if ($trip)
            <span @class([
                'rounded-full px-3 py-1 text-xs font-bold',
                'bg-sky-50 text-sky-800' => $trip->status->tone() === 'sky',
                'bg-amber-50 text-amber-900' => $trip->status->tone() === 'amber',
                'bg-emerald-50 text-emerald-800' => $trip->status->tone() === 'emerald',
                'bg-rose-50 text-rose-800' => $trip->status->tone() === 'rose',
            ])>{{ $trip->status->label() }}</span>
        @else
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">Not started</span>
        @endif
    </div>

    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Starts</dt>
            <dd class="mt-0.5 text-slate-800">{{ $starts->format('j M, H:i') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ends</dt>
            <dd class="mt-0.5 text-slate-800">{{ $ends->format('j M, H:i') }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</dt>
            <dd class="mt-0.5 text-slate-800">{{ $row->contact_name }}</dd>
            @if ($row->contact_phone)
                <dd><a href="tel:{{ $row->contact_phone }}" class="text-xs font-semibold text-emerald-800 underline">{{ $row->contact_phone }}</a></dd>
            @endif
        </div>
    </dl>

    <a href="{{ route('drivers.jobs.show', [$row->source, $row->assignment_id]) }}"
       class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
        Open this job
    </a>
</article>
