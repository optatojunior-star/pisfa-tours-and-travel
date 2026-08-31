@php
    use App\Services\Portal\CustomerActivityQuery;
    use App\Support\Money;
    use App\Support\Portal\ActivityKind;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">My PISFA</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Everything</h1>
            </div>
            <a href="{{ route('portal.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my portal</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-slate-600">
                Bookings, quotations, invoices, and payments across every PISFA service, newest first.
            </p>

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Activity counts">
                @foreach (ActivityKind::cases() as $case)
                    <a href="{{ route('portal.activity', ['kind' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $kind === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="activity-filters">
                <h2 id="activity-filters" class="sr-only">Filter your activity</h2>
                <form method="GET" action="{{ route('portal.activity') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="activity-q" class="block text-sm font-semibold">Search</label>
                        <input id="activity-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"
                               placeholder="Reference or description"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="activity-kind" class="block text-sm font-semibold">Type</label>
                        <select id="activity-kind" name="kind" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Everything</option>
                            @foreach (ActivityKind::cases() as $case)
                                <option value="{{ $case->value }}" @selected($kind === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('portal.activity') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($items->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">Nothing to show</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a different type, or clear the search.</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($items as $item)
                        @php
                            $itemKind = ActivityKind::from($item->kind);
                            $url = $itemKind->urlFor($item->source, $item->reference);
                            $at = CustomerActivityQuery::localTime($item->happened_at);
                        @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $itemKind->sourceLabel($item->source) }}</p>
                                <p class="mt-1 font-semibold text-slate-900">{{ $item->summary }}</p>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $item->reference }}</p>
                            </div>
                            <div class="text-right">
                                @if ((int) $item->amount_minor > 0)
                                    <p class="font-semibold tabular-nums text-slate-900">{{ Money::format((int) $item->amount_minor, $item->currency) }}</p>
                                @endif
                                <p class="text-xs text-slate-500">{{ $at?->format('j M Y') }}</p>
                                @if ($url)
                                    <a href="{{ $url }}" class="mt-2 inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                @else
                                    {{-- A payment has no page of its own; it is shown through the
                                         thing it paid for, and a dead link would be worse than none. --}}
                                    <p class="mt-1 text-xs text-slate-400">Shown on what it paid for</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div>{{ $items->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
