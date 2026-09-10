@extends('layouts.public')

@php
    $title = 'Compare '.$vehicles->count().' hire vehicles';
    $description = $vehicles->map(fn ($v) => trim($v->year.' '.$v->make.' '.$v->model))->join(', ', ' and ');
    $differences = collect($rows)->where('differs', true)->count();
@endphp

@section('content')
<section class="bg-emerald-950 px-4 py-12 text-white sm:px-6 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <nav aria-label="Breadcrumb" class="text-sm text-emerald-100">
            <a href="{{ route('car-hire.index', $carried) }}" class="font-bold hover:text-amber-300">&larr; Back to car hire</a>
        </nav>
        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl">Comparing {{ $vehicles->count() }} vehicles</h1>
        <p class="mt-4 max-w-2xl leading-7 text-emerald-100">
            @if ($differences === 0)
                These vehicles are specified identically. Choose on price, or on the photographs.
            @else
                {{ $differences }} {{ Str::plural('thing', $differences) }} {{ $differences === 1 ? 'differs' : 'differ' }} between them, shown first.
                Everything they share follows underneath.
            @endif
        </p>
        <p class="mt-3 text-sm text-emerald-200">
            This page's web address holds the comparison — send it to whoever is approving the trip.
        </p>
    </div>
</section>

<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    {{-- The only element allowed to scroll sideways. Four columns of vehicle
         will not fit a phone, and stacking them destroys the comparison. --}}
    <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full border-collapse text-left text-sm">
            <caption class="sr-only">
                {{ $vehicles->count() }} hire vehicles compared. Rows where the vehicles differ are listed first.
            </caption>

            <thead>
                <tr class="align-bottom">
                    <th scope="col" class="sticky left-0 z-10 w-40 bg-white p-4 text-xs font-bold uppercase tracking-wide text-slate-500 sm:w-56">
                        Vehicle
                    </th>
                    @foreach ($vehicles as $vehicle)
                        <th scope="col" class="min-w-56 border-l border-slate-200 p-4 align-top">
                            <a href="{{ route('car-hire.show', ['vehicle' => $vehicle] + $carried) }}"
                               class="block focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                                <span class="block aspect-[16/10] overflow-hidden rounded-2xl bg-emerald-950">
                                    @if ($vehicle->coverMedia)
                                        <img src="{{ $vehicle->coverMedia->url }}"
                                             alt="{{ $vehicle->coverMedia->alt_text ?: $vehicle->make.' '.$vehicle->model }}"
                                             loading="lazy" class="h-full w-full object-cover">
                                    @else
                                        <span class="flex h-full items-center justify-center text-xs font-bold text-emerald-100">No photograph</span>
                                    @endif
                                </span>
                                <span class="mt-3 block text-base font-black leading-tight text-emerald-950 hover:underline">
                                    {{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}
                                </span>
                            </a>
                            <a href="{{ route('car-hire.show', ['vehicle' => $vehicle] + $carried) }}"
                               class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-4 text-sm font-bold text-white hover:bg-emerald-900">
                                Choose this one
                            </a>
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $row)
                    <tr @class([
                        'border-t border-slate-200',
                        'bg-amber-50/50' => $row['differs'],
                    ])>
                        <th scope="row" class="sticky left-0 z-10 p-4 align-top font-bold text-slate-900 {{ $row['differs'] ? 'bg-amber-50' : 'bg-white' }}">
                            {{ $row['label'] }}
                            @if ($row['differs'])
                                <span class="ml-1 rounded-full bg-amber-300 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wide text-emerald-950">Differs</span>
                            @endif
                            @if ($row['help'])
                                <span class="mt-1 block text-xs font-normal leading-5 text-slate-500">{{ $row['help'] }}</span>
                            @endif
                        </th>
                        @foreach ($row['values'] as $value)
                            <td class="border-l border-slate-200 p-4 align-top font-semibold text-slate-800">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
        <a href="{{ route('car-hire.index', $carried) }}"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
            Back to the catalogue
        </a>
        <x-whatsapp-enquiry
            label="Ask us which one suits the trip"
            :lines="array_merge(
                ['Hello PISFA, I am choosing between these hire vehicles:'],
                $vehicles->map(fn ($v) => '- '.trim($v->year.' '.$v->make.' '.$v->model))->all(),
                ['Which would you recommend?', request()->fullUrl()],
            )" />
    </div>

    <p class="mt-6 text-xs leading-5 text-slate-500">
        Rates shown are the ones in force today and are confirmed again against your dates when you request a booking.
    </p>
</div>
@endsection
