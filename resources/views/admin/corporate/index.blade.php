@php
    use App\Enums\CorporateAccountStatus;
    use App\Support\Money;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Corporate</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Company accounts</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.corporate.groups.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    Group bookings
                </a>
                @can('create', App\Models\CorporateAccount::class)
                    <a href="{{ route('admin.corporate.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">New account</a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Account counts">
                @foreach (CorporateAccountStatus::cases() as $case)
                    <a href="{{ route('admin.corporate.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="account-filters">
                <h2 id="account-filters" class="sr-only">Filter accounts</h2>
                <form method="GET" action="{{ route('admin.corporate.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="account-q" class="block text-sm font-semibold">Search</label>
                        <input id="account-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Company, billing email, or registration"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="account-status" class="block text-sm font-semibold">Status</label>
                        <select id="account-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (CorporateAccountStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.corporate.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($accounts->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No accounts here</h2>
                    <p class="mt-2 text-sm text-slate-600">Nothing matches this filter.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Corporate accounts</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Company</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Terms</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Outstanding</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Available</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">People</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($accounts as $account)
                                    @php $position = $positions[$account->getKey()]; @endphp
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $account->name }}</p>
                                            <p class="text-xs text-slate-500">{{ $account->billing_contact_email }}</p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $account->termsSummary() }}</td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ Money::format($position['outstanding_minor'], $position['currency']) }}
                                            @if ($position['overdue_minor'] > 0)
                                                <span class="block text-xs font-bold text-rose-700">
                                                    {{ Money::format($position['overdue_minor'], $position['currency']) }} overdue
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 font-semibold text-slate-900">
                                            {{ Money::format($position['available_minor'], $position['currency']) }}
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $account->member_count }}</td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $account->status->tone() === 'slate',
                                                'bg-emerald-100 text-emerald-900' => $account->status->tone() === 'emerald',
                                                'bg-amber-100 text-amber-900' => $account->status->tone() === 'amber',
                                                'bg-rose-100 text-rose-900' => $account->status->tone() === 'rose',
                                            ])>{{ $account->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('admin.corporate.show', $account) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $accounts->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
