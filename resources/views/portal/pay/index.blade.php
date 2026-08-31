<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Pay</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Your payslips</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if ($lines->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No payslips yet</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        A payslip appears here once the month has been approved. Nothing is shown while the figures
                        can still change.
                    </p>
                </div>
            @else
                <ul class="space-y-4">
                    @foreach ($lines as $line)
                        <li>
                            <a href="{{ route('portal.payslips.show', $line) }}"
                               class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                                <div>
                                    <p class="text-lg font-black text-emerald-950">{{ $line->run?->monthLabel() }}</p>
                                    <p class="mt-1 text-sm text-slate-600">
                                        {{ $line->formattedEarnings() }} earned, {{ $line->formattedDeductions() }} deducted
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xl font-black text-emerald-800">{{ $line->formattedNet() }}</p>
                                    <p class="text-xs text-slate-500">{{ $line->run?->status->label() }}</p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</x-app-layout>
