@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Driver</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Trip history</h1>
            </div>
            <a href="{{ route('drivers.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my jobs</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if ($trips->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No completed trips yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Trips you close will be listed here.</p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($trips as $trip)
                        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs text-slate-500">{{ $trip->reference }}</p>
                                    <p class="mt-1 font-bold text-slate-900">
                                        {{ $trip->vehicle ? $trip->vehicle->make.' '.$trip->vehicle->model : 'No fleet vehicle' }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $trip->completed_at?->timezone($timezone)->format('j M Y, H:i') ?? '—' }}
                                    </p>
                                </div>
                                <div class="text-right">
                                    <span @class([
                                        'rounded-full px-3 py-1 text-xs font-bold',
                                        'bg-emerald-50 text-emerald-800' => $trip->status->tone() === 'emerald',
                                        'bg-rose-50 text-rose-800' => $trip->status->tone() === 'rose',
                                    ])>{{ $trip->status->label() }}</span>
                                    <p class="mt-2 text-lg font-black text-slate-900">{{ $trip->formattedDistance() }}</p>
                                </div>
                            </div>

                            @if ($trip->start_odometer_km !== null || $trip->end_odometer_km !== null)
                                <p class="mt-3 text-xs text-slate-600">
                                    Out {{ $trip->start_odometer_km !== null ? number_format($trip->start_odometer_km).' km' : '—' }}
                                    · In {{ $trip->end_odometer_km !== null ? number_format($trip->end_odometer_km).' km' : '—' }}
                                </p>
                            @endif

                            @if ($trip->closure_reason)
                                <p class="mt-2 text-sm text-slate-700"><span class="font-bold">Abandoned:</span> {{ $trip->closure_reason }}</p>
                            @endif

                            @php $defective = $trip->inspections->where('has_defects', true); @endphp
                            @if ($defective->isNotEmpty())
                                <p class="mt-2 text-xs font-semibold text-rose-700">
                                    {{ $defective->count() }} check(s) reported defects.
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>
                <div>{{ $trips->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
