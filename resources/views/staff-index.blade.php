<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">People &amp; access</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Team access</h1>
            </div>
            <a href="{{ route('admin.staff.create') }}" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">
                Invite team member
            </a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-semibold">The access change was not saved.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="filters-heading">
                <h2 id="filters-heading" class="sr-only">Filter team accounts</h2>
                <form method="GET" action="{{ route('admin.staff.index') }}" class="grid gap-4 md:grid-cols-[minmax(15rem,1fr)_13rem_13rem_auto] md:items-end">
                    <div>
                        <x-input-label for="q" value="Search" />
                        <x-text-input id="q" name="q" type="search" class="mt-1 block w-full" :value="$filters['q'] ?? ''" placeholder="Name, email, or phone" />
                    </div>
                    <div>
                        <x-input-label for="filter-role" value="Role" />
                        <select id="filter-role" name="role" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">All roles</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(($filters['role'] ?? '') === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="filter-status" value="Status" />
                        <select id="filter-status" name="status" class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">All statuses</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <x-primary-button>Filter</x-primary-button>
                        <a href="{{ route('admin.staff.index') }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Reset</a>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" aria-labelledby="team-heading">
                <div class="border-b border-slate-200 px-5 py-5 sm:px-6">
                    <h2 id="team-heading" class="text-lg font-bold text-slate-950">Team accounts</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $staff->total() }} {{ str('account')->plural($staff->total()) }}</p>
                </div>

                @if ($staff->isEmpty())
                    <div class="px-6 py-16 text-center">
                        <p class="font-semibold text-slate-800">No team accounts match these filters.</p>
                        <p class="mt-1 text-sm text-slate-500">Clear the filters or invite a new team member.</p>
                    </div>
                @else
                    <div class="divide-y divide-slate-200">
                        @foreach ($staff as $person)
                            @php($invitation = $latestInvitations->get($person->id))
                            <article class="grid gap-5 px-5 py-6 lg:grid-cols-[minmax(14rem,0.8fr)_minmax(26rem,1.2fr)] lg:items-start lg:px-6" aria-labelledby="staff-{{ $person->id }}">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 id="staff-{{ $person->id }}" class="truncate font-bold text-slate-950">{{ $person->name }}</h3>
                                        @if ($person->is(auth()->user()))
                                            <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700">You</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 break-all text-sm text-slate-600">{{ $person->email }}</p>
                                    @if ($person->phone)
                                        <p class="mt-1 text-sm text-slate-500">{{ $person->phone }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                                        <span @class([
                                            'rounded-full px-2.5 py-1',
                                            'bg-emerald-50 text-emerald-700' => $person->status->value === 'active',
                                            'bg-amber-50 text-amber-700' => $person->status->value === 'inactive',
                                            'bg-rose-50 text-rose-700' => $person->status->value === 'suspended',
                                        ])>{{ $person->status->label() }}</span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ $person->role->label() }}</span>
                                        @if ($person->two_factor_required)
                                            <span class="rounded-full bg-violet-50 px-2.5 py-1 text-violet-700">2FA required</span>
                                        @endif
                                    </div>

                                    @if ($invitation)
                                        <p class="mt-4 text-xs leading-5 text-slate-500">
                                            @if ($invitation->accepted_at)
                                                Invitation accepted {{ $invitation->accepted_at->diffForHumans() }}.
                                            @elseif ($invitation->revoked_at)
                                                Previous invitation revoked.
                                            @elseif ($invitation->expires_at->isPast())
                                                Invitation expired {{ $invitation->expires_at->diffForHumans() }}.
                                            @else
                                                Invitation pending until {{ $invitation->expires_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}.
                                            @endif
                                        </p>
                                    @endif
                                </div>

                                <div class="space-y-3">
                                    <form method="POST" action="{{ route('admin.staff.update', $person) }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_auto_auto] xl:items-end">
                                        @csrf
                                        @method('PATCH')
                                        <div>
                                            <label for="role-{{ $person->id }}" class="block text-xs font-semibold text-slate-700">Role</label>
                                            <select id="role-{{ $person->id }}" name="role" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                                @foreach ($roles as $role)
                                                    <option value="{{ $role->value }}" @selected($person->role === $role) @disabled($person->is(auth()->user()) && $role->value !== 'super_admin')>{{ $role->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label for="status-{{ $person->id }}" class="block text-xs font-semibold text-slate-700">Status</label>
                                            <select id="status-{{ $person->id }}" name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                                                @foreach ($statuses as $status)
                                                    <option value="{{ $status->value }}" @selected($person->status === $status) @disabled(($person->is(auth()->user()) && $status->value !== 'active') || ($person->email_verified_at === null && $status->value === 'active'))>{{ $status->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-slate-700">
                                            <input type="hidden" name="two_factor_required" value="0">
                                            <input type="checkbox" name="two_factor_required" value="1" @checked($person->two_factor_required) class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-500">
                                            Require 2FA
                                        </label>
                                        <button type="submit" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-bold text-white hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-600">Save access</button>

                                        @if ($person->is(auth()->user()))
                                            <p class="text-xs leading-5 text-slate-500 sm:col-span-2 xl:col-span-4">Your own super-administrator role and active status cannot be reduced here.</p>
                                        @elseif ($person->email_verified_at === null)
                                            <p class="text-xs leading-5 text-slate-500 sm:col-span-2 xl:col-span-4">Activation becomes available after this person accepts their invitation and verifies control of the email address.</p>
                                        @endif
                                    </form>

                                    @if ($person->status->value === 'inactive' && (! $invitation || ! $invitation->accepted_at))
                                        <form method="POST" action="{{ route('admin.staff.resend', $person) }}" class="text-right">
                                            @csrf
                                            <button type="submit" class="text-sm font-semibold text-emerald-800 underline decoration-emerald-300 underline-offset-4 hover:text-emerald-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Send a new invitation</button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            {{ $staff->links() }}
        </div>
    </div>
</x-app-layout>
