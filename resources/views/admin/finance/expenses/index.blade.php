@php
    use App\Enums\ExpenseCategory;
    use App\Enums\ExpenseStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Finance</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Expense claims</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Claim counts">
                @foreach ([ExpenseStatus::Submitted, ExpenseStatus::Approved, ExpenseStatus::Reimbursed, ExpenseStatus::Rejected] as $case)
                    <a href="{{ route('admin.expenses.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            @if ($totals !== [])
                <section class="rounded-2xl border border-slate-200 bg-white p-5" aria-label="Approved spend this month">
                    <h2 class="text-sm font-bold text-slate-900">Approved spend this month</h2>
                    <div class="mt-2 flex flex-wrap gap-6">
                        @foreach ($totals as $code => $total)
                            <p class="text-lg font-black text-slate-900">
                                {{ $total }}
                                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $code }}</span>
                            </p>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-slate-500">
                        Shown per currency. Adding them together would be a lie — money is never summed across
                        currencies.
                    </p>
                </section>
            @endif

            @if (($counts['awaiting_recovery'] ?? 0) > 0)
                <p class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm font-semibold text-sky-900">
                    {{ $counts['awaiting_recovery'] }} approved
                    {{ Str::plural('item', $counts['awaiting_recovery']) }} of vehicle spending
                    {{ $counts['awaiting_recovery'] === 1 ? 'is' : 'are' }} waiting to be charged to a lease owner.
                </p>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="expense-filters">
                <h2 id="expense-filters" class="sr-only">Filter claims</h2>
                <form method="GET" action="{{ route('admin.expenses.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div>
                        <label for="expense-q" class="block text-sm font-semibold">Search</label>
                        <input id="expense-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Reference, description, or supplier"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="expense-category" class="block text-sm font-semibold">Category</label>
                        <select id="expense-category" name="category" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All categories</option>
                            @foreach (ExpenseCategory::cases() as $case)
                                <option value="{{ $case->value }}" @selected($category === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="expense-status" class="block text-sm font-semibold">Status</label>
                        <select id="expense-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Awaiting approval</option>
                            @foreach (ExpenseStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm font-semibold sm:col-span-3">
                        <input type="checkbox" name="show" value="all" @checked($showAll)
                               class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                        Show everything
                    </label>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.expenses.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($expenses->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">Nothing to approve</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        The queue is clear. Tick "show everything" to see claims already decided.
                    </p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Expense claims</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Claim</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Who</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Vehicle</th>
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
                                                {{ $expense->reference }} · {{ $expense->category->label() }} ·
                                                {{ $expense->spent_on->format('j M Y') }}
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $expense->incurredBy?->name ?? '—' }}</td>
                                        <td class="px-5 py-4 font-mono text-xs text-slate-700">
                                            {{ $expense->vehicle?->registration_plate ?? '—' }}
                                        </td>
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
                                            @if ($expense->is_recoverable)
                                                <span class="mt-1 block text-xs font-semibold text-sky-800">Chargeable to owner</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.expenses.show', $expense) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
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
