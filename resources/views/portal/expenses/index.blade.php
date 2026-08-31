@php
    use App\Enums\ExpenseStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Expenses</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Your claims</h1>
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
                <h2 class="text-lg font-black text-slate-900">Claim what you spent</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Save it as a draft, attach the receipt, then send it for approval. Somebody else approves it —
                    never you.
                </p>

                <form method="POST" action="{{ route('portal.expenses.store') }}" class="mt-5 grid gap-4 sm:grid-cols-6">
                    @csrf
                    <div class="sm:col-span-2">
                        <label for="category" class="block text-sm font-semibold">What for</label>
                        <select id="category" name="category" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach ($categories as $case)
                                <option value="{{ $case->value }}" @selected(old('category') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="spent_on" class="block text-sm font-semibold">When</label>
                        <input id="spent_on" name="spent_on" type="date" required
                               value="{{ old('spent_on', now($timezone)->toDateString()) }}"
                               max="{{ now($timezone)->toDateString() }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="amount" class="block text-sm font-semibold">Amount</label>
                        <input id="amount" name="amount" type="text" inputmode="numeric" required maxlength="24"
                               value="{{ old('amount') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-semibold">Currency</label>
                        <select id="currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                <option value="{{ $code }}" @selected(old('currency', config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-4">
                        <label for="description" class="block text-sm font-semibold">What was it</label>
                        <input id="description" name="description" type="text" required minlength="3" maxlength="255"
                               value="{{ old('description') }}" placeholder="Diesel for the Kampala run"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="supplier" class="block text-sm font-semibold">Where</label>
                        <input id="supplier" name="supplier" type="text" maxlength="180" value="{{ old('supplier') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="sm:col-span-3">
                        <label for="vehicle_id" class="block text-sm font-semibold">Vehicle</label>
                        <select id="vehicle_id" name="vehicle_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Not for a particular vehicle</option>
                            @foreach ($vehicles as $vehicle)
                                <option value="{{ $vehicle->getKey() }}" @selected((int) old('vehicle_id') === (int) $vehicle->getKey())>
                                    {{ $vehicle->registration_plate }} — {{ $vehicle->make }} {{ $vehicle->model }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Office and marketing spending belongs to no vehicle.</p>
                    </div>
                    <div class="sm:col-span-6">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                            Save as draft
                        </button>
                    </div>
                </form>
            </section>

            @if ($expenses->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No claims yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Anything you spend on the company goes here.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Your expense claims</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">What</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">When</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Amount</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($expenses as $expense)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $expense->description }}</p>
                                            <p class="text-xs text-slate-500">
                                                {{ $expense->reference }} · {{ $expense->category->label() }}
                                                @if ($expense->vehicle)
                                                    · {{ $expense->vehicle->registration_plate }}
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $expense->spent_on->format('j M Y') }}</td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">{{ $expense->formattedAmount() }}</td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $expense->status->tone() === 'slate',
                                                'bg-amber-100 text-amber-900' => $expense->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $expense->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $expense->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $expense->status->tone() === 'rose',
                                            ])>{{ $expense->status->label() }}</span>
                                            @if ($expense->closure_reason)
                                                <span class="mt-1 block text-xs text-slate-500">{{ $expense->closure_reason }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            @if ($expense->status === ExpenseStatus::Draft)
                                                <form method="POST" action="{{ route('portal.expenses.submit', $expense) }}">
                                                    @csrf
                                                    <button type="submit" class="text-sm font-bold text-emerald-800 hover:underline">
                                                        Send for approval
                                                    </button>
                                                </form>
                                            @elseif ($expense->reimbursement_reference)
                                                <span class="text-xs text-slate-500">{{ $expense->reimbursement_reference }}</span>
                                            @else
                                                <span class="text-xs text-slate-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $expenses->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
