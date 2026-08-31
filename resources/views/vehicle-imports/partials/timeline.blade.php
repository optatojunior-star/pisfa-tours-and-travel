@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp

<section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-timeline">
    <h2 id="import-timeline" class="text-lg font-black text-slate-950">Progress timeline</h2>

    @if ($timeline->isEmpty())
        <p class="mt-3 text-sm text-slate-600">Nothing recorded yet.</p>
    @else
        <ol class="mt-5 space-y-4">
            @foreach ($timeline as $event)
                <li class="relative border-l-2 border-emerald-200 pl-5">
                    <span class="absolute left-[-7px] top-1.5 block size-3 rounded-full bg-emerald-600" aria-hidden="true"></span>
                    <p class="text-sm font-semibold text-slate-900">{{ $event->summary }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $event->created_at->timezone($timezone)->format('j M Y, H:i') }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</section>
