@php
    use App\Enums\CorporateAccountStatus;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canSetTerms = auth()->user()?->can('setTerms', $account) ?? false;
    $canTransition = auth()->user()?->can('transition', $account) ?? false;
    $canManageMembers = auth()->user()?->can('manageMembers', $account) ?? false;
    $canEdit = auth()->user()?->can('update', $account) ?? false;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Company account</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $account->name }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $account->termsSummary() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canEdit)
                    <a href="{{ route('admin.corporate.edit', $account) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                        Edit
                    </a>
                @endif
                <a href="{{ route('admin.corporate.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    All accounts
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
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

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Credit position">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credit limit</p>
                    <p class="mt-1 text-xl font-black text-slate-900">
                        {{ Money::format($position['limit_minor'], $position['currency']) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outstanding</p>
                    <p class="mt-1 text-xl font-black text-slate-900">
                        {{ Money::format($position['outstanding_minor'], $position['currency']) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $position['invoice_count'] }} {{ Str::plural('invoice', $position['invoice_count']) }}
                    </p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Available</p>
                    <p class="mt-1 text-xl font-black text-emerald-800">
                        {{ Money::format($position['available_minor'], $position['currency']) }}
                    </p>
                </div>
                <div @class([
                    'rounded-2xl border p-5',
                    'border-rose-200 bg-rose-50' => $position['overdue_minor'] > 0,
                    'border-slate-200 bg-white' => $position['overdue_minor'] < 1,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Overdue</p>
                    <p @class([
                        'mt-1 text-xl font-black',
                        'text-rose-800' => $position['overdue_minor'] > 0,
                        'text-slate-900' => $position['overdue_minor'] < 1,
                    ])>{{ Money::format($position['overdue_minor'], $position['currency']) }}</p>
                </div>
            </section>

            @if ($position['other_currency_count'] > 0)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    {{ $position['other_currency_count'] }}
                    {{ Str::plural('invoice', $position['other_currency_count']) }} on this account
                    {{ $position['other_currency_count'] === 1 ? 'is' : 'are' }} in another currency and
                    {{ $position['other_currency_count'] === 1 ? 'is' : 'are' }} not counted above. Money is never
                    summed across currencies — settle those separately.
                </p>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center gap-3">
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                                'bg-slate-100 text-slate-800' => $account->status->tone() === 'slate',
                                'bg-emerald-100 text-emerald-900' => $account->status->tone() === 'emerald',
                                'bg-amber-100 text-amber-900' => $account->status->tone() === 'amber',
                                'bg-rose-100 text-rose-900' => $account->status->tone() === 'rose',
                            ])>{{ $account->status->label() }}</span>
                        </div>

                        @if ($account->suspension_reason && $account->status === CorporateAccountStatus::Suspended)
                            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                <span class="font-bold">Suspended:</span> {{ $account->suspension_reason }}
                            </p>
                        @endif

                        @if ($account->closure_reason && $account->status === CorporateAccountStatus::Closed)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closed:</span> {{ $account->closure_reason }}
                            </p>
                        @endif

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Billing contact</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $account->billing_contact_name }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Email</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    <a href="mailto:{{ $account->billing_contact_email }}" class="text-emerald-800 hover:underline">
                                        {{ $account->billing_contact_email }}
                                    </a>
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Registration</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $account->registration_number ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Trading since</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $account->activated_at?->timezone($timezone)->format('j M Y') ?? 'Not yet' }}
                                </dd>
                            </div>
                        </dl>

                        @if ($account->internal_notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">Internal notes</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $account->internal_notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Who is on the account</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Authority is a membership, not a flag. Revoking it leaves their bookings alone.
                        </p>

                        @if ($account->members->isEmpty())
                            <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Nobody yet.</p>
                        @else
                            <ul class="mt-5 divide-y divide-slate-100">
                                @foreach ($account->members as $member)
                                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div>
                                            <p class="font-bold text-slate-900">
                                                {{ $member->user?->name ?? 'Removed user' }}
                                                @unless ($member->is_active)
                                                    <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">Revoked</span>
                                                @endunless
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                {{ $member->role->label() }}
                                                @if ($member->job_title)
                                                    · {{ $member->job_title }}
                                                @endif
                                                @if ($member->user)
                                                    · {{ $member->user->email }}
                                                @endif
                                            </p>
                                        </div>
                                        @if ($canManageMembers && $member->is_active)
                                            <form method="POST" action="{{ route('admin.corporate.members.destroy', [$account, $member]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">
                                                    Revoke access
                                                </button>
                                            </form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($canManageMembers)
                            <form method="POST" action="{{ route('admin.corporate.members.store', $account) }}" class="mt-6 grid gap-3 sm:grid-cols-4">
                                @csrf
                                <div class="sm:col-span-2">
                                    <label for="user_id" class="block text-xs font-semibold">Person</label>
                                    <select id="user_id" name="user_id" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        <option value="">Choose a customer</option>
                                        @foreach ($candidates as $person)
                                            <option value="{{ $person->getKey() }}">{{ $person->name }} ({{ $person->email }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="role" class="block text-xs font-semibold">Role</label>
                                    <select id="role" name="role" class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                        @foreach ($roles as $role)
                                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="job_title" class="block text-xs font-semibold">Job title</label>
                                    <input id="job_title" name="job_title" type="text" maxlength="120"
                                           class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div class="sm:col-span-4">
                                    <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                        Add to the account
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Recent group trips</h2>

                        @if ($groups->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">No group bookings on this account yet.</p>
                        @else
                            <ul class="mt-4 divide-y divide-slate-100">
                                @foreach ($groups as $group)
                                    <li class="py-3">
                                        <a href="{{ route('admin.corporate.groups.show', $group) }}" class="font-bold text-emerald-800 hover:underline">
                                            {{ $group->title }}
                                        </a>
                                        <p class="text-xs text-slate-500">
                                            {{ $group->dateLabel() }} · {{ $group->headcount }} people ·
                                            {{ $group->status->label() }}
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($canSetTerms)
                        <form method="POST" action="{{ route('admin.corporate.terms', $account) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            @method('PATCH')
                            <h2 class="text-lg font-black text-slate-900">Terms</h2>
                            <p class="text-sm text-slate-600">
                                The limit cannot go below what the company already owes.
                            </p>
                            <div>
                                <label for="terms-days" class="block text-sm font-semibold">Payment terms (days)</label>
                                <input id="terms-days" name="payment_terms_days" type="number" required min="0" max="180"
                                       value="{{ $account->payment_terms_days }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="col-span-2">
                                    <label for="terms-limit" class="block text-sm font-semibold">Credit limit</label>
                                    <input id="terms-limit" name="credit_limit" type="text" inputmode="numeric" required maxlength="24"
                                           value="{{ \App\Support\Money::forInput($account->credit_limit_minor, $account->currency) }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div>
                                    <label for="terms-currency" class="block text-sm font-semibold">Currency</label>
                                    <select id="terms-currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                            <option value="{{ $code }}" @selected($account->currency === $code)>{{ $code }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label for="terms-discount" class="block text-sm font-semibold">Discount (bps)</label>
                                <input id="terms-discount" name="discount_bps" type="number" min="0"
                                       max="{{ (int) config('corporate.max_discount_bps', 9900) }}"
                                       value="{{ $account->discount_bps }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Save terms
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(CorporateAccountStatus::Active, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.activate', $account) }}" class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">
                                {{ $account->status === CorporateAccountStatus::Suspended ? 'Lift the suspension' : 'Activate' }}
                            </h2>
                            <p class="mt-2 text-sm text-slate-600">
                                The account can book and be invoiced on terms.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                {{ $account->status === CorporateAccountStatus::Suspended ? 'Reinstate' : 'Activate' }}
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(CorporateAccountStatus::Suspended, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.suspend', $account) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Suspend</h2>
                            <p class="text-sm text-slate-600">
                                Stops new bookings on credit. The terms and the invoices already out are untouched.
                            </p>
                            <div>
                                <label for="suspend-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="suspend-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Two invoices past due"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-amber-300 px-5 text-sm font-bold text-amber-800 hover:bg-amber-50">
                                Suspend
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(CorporateAccountStatus::Closed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.close', $account) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Close</h2>
                            <p class="text-sm text-slate-600">
                                Refused while money is owed or a trip is still to run.
                            </p>
                            <div>
                                <label for="close-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="close-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Close the account
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && $account->status === CorporateAccountStatus::Closed)
                        <form method="POST" action="{{ route('admin.corporate.reopen', $account) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Reopen</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Comes back as a prospect, so the terms are agreed again rather than an old credit
                                limit quietly returning.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Reopen as a prospect
                            </button>
                        </form>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
