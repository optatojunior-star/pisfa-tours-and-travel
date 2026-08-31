@php
    use App\Enums\ExpenseStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canDecide = auth()->user()?->can('decide', $expense) ?? false;
    $isOwnClaim = (int) $expense->incurred_by_user_id === (int) auth()->id();
    $couldBeRecovered = $expense->vehicle_id !== null && $expense->category->isRecoverableFromOwner();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Expense claim</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $expense->description }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $expense->reference }}</p>
            </div>
            <a href="{{ route('admin.expenses.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All claims
            </a>
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

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-slate-100 text-slate-800' => $expense->status->tone() === 'slate',
                            'bg-amber-100 text-amber-900' => $expense->status->tone() === 'amber',
                            'bg-sky-100 text-sky-900' => $expense->status->tone() === 'sky',
                            'bg-emerald-100 text-emerald-900' => $expense->status->tone() === 'emerald',
                            'bg-rose-100 text-rose-900' => $expense->status->tone() === 'rose',
                        ])>{{ $expense->status->label() }}</span>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Amount</dt>
                                <dd class="text-sm font-black text-slate-900">{{ $expense->formattedAmount() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Category</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $expense->category->label() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Spent on</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $expense->spent_on->format('j M Y') }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Claimed by</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $expense->incurredBy?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Supplier</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $expense->supplier ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Vehicle</dt>
                                <dd class="font-mono text-sm font-bold text-slate-900">
                                    {{ $expense->vehicle?->registration_plate ?? 'Not vehicle spending' }}
                                </dd>
                            </div>
                            @if ($expense->approvedBy)
                                <div class="flex justify-between border-b border-slate-200 py-2">
                                    <dt class="text-sm font-semibold text-slate-600">Approved by</dt>
                                    <dd class="text-sm font-bold text-slate-900">{{ $expense->approvedBy->name }}</dd>
                                </div>
                            @endif
                            @if ($expense->reimbursement_reference)
                                <div class="flex justify-between border-b border-slate-200 py-2">
                                    <dt class="text-sm font-semibold text-slate-600">Transfer reference</dt>
                                    <dd class="text-sm font-bold text-slate-900">{{ $expense->reimbursement_reference }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($expense->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Reason:</span> {{ $expense->closure_reason }}
                            </p>
                        @endif

                        @if ($expense->hasBeenRecovered())
                            <p class="mt-4 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
                                Charged to the owner on statement
                                @if ($expense->recoveredOnPayout?->lease)
                                    <a href="{{ route('admin.leasing.leases.show', $expense->recoveredOnPayout->lease) }}" class="font-bold underline">
                                        {{ $expense->recoveredOnPayout->reference }}
                                    </a>
                                @else
                                    {{ $expense->recoveredOnPayout?->reference }}
                                @endif
                                — it cannot be recovered again.
                            </p>
                        @endif

                        @if ($expense->internal_notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">Internal notes</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $expense->internal_notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Receipts</h2>
                        @if ($expense->receipts->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">
                                No receipt attached. Ask the claimant for one before approving.
                            </p>
                        @else
                            <ul class="mt-4 space-y-2">
                                @foreach ($expense->receipts as $receipt)
                                    <li>
                                        <a href="{{ route('documents.show', $receipt) }}" class="text-sm font-bold text-emerald-800 hover:underline">
                                            {{ $receipt->original_name }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($canDecide && $isOwnClaim && in_array(ExpenseStatus::Approved, $nextStatuses, true))
                        <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
                            <h2 class="text-lg font-black text-amber-900">This is your own claim</h2>
                            <p class="mt-2 text-sm text-amber-900">
                                Somebody else has to approve it. A second pair of eyes is the whole control.
                            </p>
                        </div>
                    @elseif ($canDecide && in_array(ExpenseStatus::Approved, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.expenses.approve', $expense) }}" class="space-y-4 rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Approve</h2>

                            @if ($couldBeRecovered)
                                <label class="flex items-start gap-3">
                                    <input type="hidden" name="is_recoverable" value="0">
                                    <input type="checkbox" name="is_recoverable" value="1"
                                           class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                    <span>
                                        <span class="block text-sm font-semibold">Chargeable to the lease owner</span>
                                        <span class="block text-xs text-slate-500">
                                            Adds it to the queue for the owner's next statement. It can only ever be
                                            charged once.
                                        </span>
                                    </span>
                                </label>
                            @endif

                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Approve the claim
                            </button>
                        </form>
                    @endif

                    @if ($canDecide && in_array(ExpenseStatus::Reimbursed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.expenses.reimburse', $expense) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Record the reimbursement</h2>
                            <div>
                                <label for="reimbursement_reference" class="block text-sm font-semibold">Transfer reference</label>
                                <input id="reimbursement_reference" name="reimbursement_reference" type="text" required minlength="3" maxlength="120"
                                       placeholder="MM-12345678"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark reimbursed
                            </button>
                        </form>
                    @endif

                    @if ($canDecide && in_array(ExpenseStatus::Draft, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.expenses.return', $expense) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Send it back</h2>
                            <div>
                                <label for="return-reason" class="block text-sm font-semibold">What needs fixing</label>
                                <input id="return-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="The receipt is unreadable"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Return for correction
                            </button>
                        </form>
                    @endif

                    @if ($canDecide && in_array(ExpenseStatus::Rejected, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.expenses.reject', $expense) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Reject</h2>
                            <p class="text-sm text-slate-600">The claimant is told the reason, so make it useful.</p>
                            <div>
                                <label for="reject-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="reject-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Reject
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">This claim has reached the end of its life.</p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
