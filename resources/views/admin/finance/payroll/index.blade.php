@php
    use App\Enums\PayrollRunStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Finance</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Payroll</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
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

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Open a month</h2>
                <p class="mt-1 text-sm text-slate-600">
                    One run per month per currency. Staff paid in a second currency get a second run — money is never
                    summed across currencies.
                </p>
                <form method="POST" action="{{ route('admin.payroll.store') }}" class="mt-5 flex flex-wrap items-end gap-3">
                    @csrf
                    <div>
                        <label for="month" class="block text-sm font-semibold">Month</label>
                        <input id="month" name="month" type="date" required
                               value="{{ now()->subMonth()->startOfMonth()->toDateString() }}"
                               class="mt-1 block rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-semibold">Currency</label>
                        <select id="currency" name="currency" class="mt-1 block rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}" @selected($defaultCurrency === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                        Open the run
                    </button>
                </form>
            </section>

            @if ($runs->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No runs yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Open a month above to start.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Payroll runs</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Month</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">People</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Gross</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Net</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Cost to PISFA</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($runs as $run)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $run->monthLabel() }}</p>
                                            <p class="text-xs text-slate-500">{{ $run->reference }} · {{ $run->currency }}</p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $run->lines_count }}</td>
                                        <td class="px-5 py-4 text-slate-700">{{ $run->formattedGross() }}</td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">{{ $run->formattedNet() }}</td>
                                        <td class="px-5 py-4 text-slate-700">{{ $run->formattedEmployerCost() }}</td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $run->status->tone() === 'slate',
                                                'bg-sky-100 text-sky-900' => $run->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $run->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $run->status->tone() === 'rose',
                                            ])>{{ $run->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.payroll.show', $run) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $runs->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
