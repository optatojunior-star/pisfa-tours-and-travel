@php
    use App\Enums\BookingStage;
    use App\Support\Money;

    $query = $period->toQueryString();
    // Days with no takings still get a bar of zero height, so a gap in trade
    // reads as a gap rather than as missing data.
    $peak = $showsMoney ? max(1, ...array_values($revenue['daily'] ?: [0])) : 1;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Reports</h1>
            </div>
            <p class="text-sm text-slate-500">{{ $period->label() }} ({{ $period->timezone }})</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="period-heading">
                <h2 id="period-heading" class="sr-only">Choose a reporting period</h2>
                <form method="GET" action="{{ route('admin.reports.index') }}" class="grid gap-4 sm:grid-cols-4 sm:items-end">
                    <div>
                        <label for="from" class="block text-sm font-semibold">From</label>
                        <input id="from" name="from" type="date" value="{{ $period->startDate->toDateString() }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="to" class="block text-sm font-semibold">To</label>
                        <input id="to" name="to" type="date" value="{{ $period->endDate->toDateString() }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.reports.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Last 30 days</a>
                    </div>
                </form>

                @if ($errors->any())
                    <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                        <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
            </section>

            @if ($showsMoney)
                <section aria-labelledby="revenue-heading">
                    <h2 id="revenue-heading" class="text-lg font-black text-slate-950">Revenue</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Settled payments. The base-currency figure uses the rate stamped on each payment when it
                        settled, so a later rate change cannot move a past total.
                    </p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Collected ({{ $revenue['base_currency'] }})</p>
                            <p class="mt-1 text-2xl font-black text-emerald-800">
                                {{ Money::format($revenue['current']['base_total_minor'], $revenue['base_currency']) }}
                            </p>
                            @if ($revenue['change_percent'] !== null)
                                <p @class([
                                    'mt-1 text-xs font-bold',
                                    'text-emerald-700' => $revenue['change_percent'] >= 0,
                                    'text-rose-700' => $revenue['change_percent'] < 0,
                                ])>
                                    {{ $revenue['change_percent'] >= 0 ? '+' : '' }}{{ $revenue['change_percent'] }}% on the previous {{ $period->days() }} days
                                </p>
                            @else
                                <p class="mt-1 text-xs text-slate-500">No comparable previous period</p>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Payments</p>
                            <p class="mt-1 text-2xl font-black text-slate-900">{{ $revenue['current']['payments'] }}</p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 sm:col-span-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Received per currency</p>
                            @if ($revenue['by_currency'] === [])
                                <p class="mt-1 text-sm text-slate-500">Nothing settled in this period.</p>
                            @else
                                <dl class="mt-2 space-y-1 text-sm">
                                    @foreach ($revenue['by_currency'] as $currency => $row)
                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-slate-500">{{ $currency }}</dt>
                                            <dd class="tabular-nums font-semibold text-slate-800">
                                                {{ Money::format($row['collected_minor'], $currency) }}
                                                <span class="ml-1 text-xs font-normal text-slate-500">({{ $row['payments'] }})</span>
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </div>

                    @if ($revenue['refunds'] !== [])
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-900">Refunds in this period</p>
                            {{-- Shown separately rather than netted off: a month
                                 with heavy refunds against last month's takings
                                 is a fact worth seeing. --}}
                            <dl class="mt-2 space-y-1 text-sm">
                                @foreach ($revenue['refunds'] as $currency => $row)
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-amber-900">{{ $currency }}</dt>
                                        <dd class="tabular-nums font-semibold text-amber-950">
                                            {{ Money::format($row['amount_minor'], $currency) }}
                                            <span class="ml-1 text-xs font-normal">({{ $row['count'] }})</span>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-3xl border border-slate-200 bg-white p-6">
                            <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">By service</h3>
                            @if ($revenue['by_service'] === [])
                                <p class="mt-3 text-sm text-slate-500">Nothing to report.</p>
                            @else
                                <dl class="mt-3 space-y-2 text-sm">
                                    @foreach ($revenue['by_service'] as $service => $row)
                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-slate-600">{{ $service }}</dt>
                                            <dd class="tabular-nums font-semibold text-slate-900">{{ Money::format($row['base_minor'], $revenue['base_currency']) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>

                        <div class="rounded-3xl border border-slate-200 bg-white p-6">
                            <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">By provider</h3>
                            @if ($revenue['by_provider'] === [])
                                <p class="mt-3 text-sm text-slate-500">Nothing to report.</p>
                            @else
                                <dl class="mt-3 space-y-2 text-sm">
                                    @foreach ($revenue['by_provider'] as $provider => $row)
                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-slate-600">{{ str($provider)->replace('_', ' ')->headline() }}</dt>
                                            <dd class="tabular-nums font-semibold text-slate-900">{{ Money::format($row['base_minor'], $revenue['base_currency']) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </div>

                    @if ($period->days() <= 92)
                        <div class="mt-4 overflow-x-auto rounded-3xl border border-slate-200 bg-white p-6">
                            <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">Daily</h3>
                            <div class="mt-4 flex min-w-full items-end gap-1" role="img"
                                 aria-label="Daily revenue for {{ $period->label() }}">
                                @foreach ($revenue['daily'] as $day => $minor)
                                    <span class="flex flex-1 flex-col items-center gap-1" title="{{ $day }}: {{ Money::format($minor, $revenue['base_currency']) }}">
                                        <span class="w-full rounded-t bg-emerald-500"
                                              style="height: {{ max(2, (int) round($minor / $peak * 96)) }}px"></span>
                                    </span>
                                @endforeach
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ $period->startDate->format('j M') }} to {{ $period->endDate->format('j M') }} ·
                                peak {{ Money::format($peak, $revenue['base_currency']) }}
                            </p>
                        </div>
                    @endif
                </section>
            @else
                <div class="rounded-3xl border border-slate-200 bg-stone-50 p-6">
                    <h2 class="text-lg font-black text-slate-950">Revenue is restricted</h2>
                    <p class="mt-2 text-sm text-slate-700">
                        Financial reporting is available to managers and above. The operational figures below are
                        yours.
                    </p>
                </div>
            @endif

            <section aria-labelledby="operations-heading">
                <h2 id="operations-heading" class="text-lg font-black text-slate-950">Operations</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ([
                        ['Quotation requests', $operations['conversion']['requests']],
                        ['Quotations sent', $operations['conversion']['quotations_sent']],
                        ['Accepted', $operations['conversion']['quotations_accepted']],
                        ['Invoices issued', $operations['conversion']['invoices_issued']],
                        ['Invoices paid', $operations['conversion']['invoices_paid']],
                    ] as [$label, $value])
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-black text-slate-900">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($operations['conversion']['acceptance_rate'] !== null)
                    <p class="mt-3 text-sm text-slate-600">
                        <span class="font-bold">{{ $operations['conversion']['acceptance_rate'] }}%</span>
                        of quotations sent in this period were accepted.
                    </p>
                @endif

                <div class="mt-6 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Bookings received per service and stage</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Service</th>
                                @foreach (BookingStage::cases() as $stage)
                                    <th scope="col" class="px-4 py-3 text-right">{{ $stage->label() }}</th>
                                @endforeach
                                <th scope="col" class="px-4 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($operations['bookings']['by_source'] as $row)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-slate-800">{{ $row['label'] }}</td>
                                    @foreach (BookingStage::cases() as $stage)
                                        <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $row['by_stage'][$stage->value] ?? 0 }}</td>
                                    @endforeach
                                    <td class="px-4 py-3 text-right font-black tabular-nums text-slate-900">{{ $row['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-stone-50">
                            <tr>
                                <td class="px-4 py-3 font-black text-slate-900" colspan="{{ count(BookingStage::cases()) + 1 }}">Bookings received</td>
                                <td class="px-4 py-3 text-right font-black tabular-nums text-slate-900">{{ $operations['bookings']['total'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reviews published</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $operations['reviews']['published'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $operations['reviews']['average_rating'] !== null
                                ? number_format($operations['reviews']['average_rating'], 1).' average'
                                : 'No average yet' }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Trips completed</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $operations['fleet']['trips_completed'] }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ number_format($operations['fleet']['distance_km']) }} km driven</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Defects reported</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $operations['fleet']['defects_reported'] }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Maintenance closed</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $operations['fleet']['maintenance_completed'] }}</p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="exports-heading">
                <h2 id="exports-heading" class="text-lg font-black text-slate-950">Exports</h2>
                <p class="mt-1 text-sm text-slate-600">
                    CSV for the period above, streamed rather than assembled, and recorded in the audit log.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($datasets as $dataset)
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <h3 class="font-bold text-slate-900">{{ $dataset->label() }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $dataset->description() }}</p>
                            <a href="{{ route('admin.reports.export', array_merge([$dataset->value], $query)) }}"
                               class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                                Download CSV
                            </a>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
