@php
    use App\Enums\FlightInquiryStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $allowedTransitions = collect($inquiry->status->allowedTransitions())
        ->reject(fn (FlightInquiryStatus $next): bool => $next === FlightInquiryStatus::New && ! $canReopen)
        ->values();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Flight enquiry</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $inquiry->reference }}</h1>
            </div>
            <a href="{{ route('admin.flight-inquiries.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to enquiries</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">This operation was rejected.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-flight-summary">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="admin-flight-summary" class="text-lg font-black text-slate-950">{{ $inquiry->routeLabel() }}</h2>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $inquiry->status->label() }}</span>
                </div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Flight type</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->scope->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Trip</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->trip_type->label() }} · {{ $inquiry->travel_class->label() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Travellers</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->passenger_count }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Outbound</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->outbound_on->format('j M Y') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Return</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->return_on?->format('j M Y') ?? 'One way' }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Received</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->created_at->timezone($timezone)->format('j M Y, H:i') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Traveller</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->contact_name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->contact_email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phone</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->contact_phone }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->isGuest() ? 'Guest enquiry' : $inquiry->customer?->name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reopened</dt><dd class="mt-1 font-bold text-slate-900">{{ $inquiry->reopen_count }} time(s)</dd></div>
                    @if (filled($inquiry->notes))
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Traveller notes</dt><dd class="mt-1 text-slate-800">{{ $inquiry->notes }}</dd></div>
                    @endif
                    @if (filled($inquiry->resolution_reason))
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Resolution reason</dt><dd class="mt-1 text-slate-800">{{ $inquiry->resolution_reason }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-flight-assignment">
                <h2 id="admin-flight-assignment" class="text-lg font-black text-slate-950">Consultant</h2>
                <p class="mt-2 text-sm text-slate-600">Currently {{ $inquiry->assignee?->name ?? 'unassigned' }}@if ($inquiry->assigned_at !== null) since {{ $inquiry->assigned_at->timezone($timezone)->format('j M Y, H:i') }}@endif.</p>

                @if (! $inquiry->isOpen())
                    <p class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-700" role="note">A {{ mb_strtolower($inquiry->status->label()) }} enquiry cannot be reassigned. Reopen it first.</p>
                @else
                    <form method="POST" action="{{ route('admin.flight-inquiries.assignment', $inquiry) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="assign-consultant" class="block text-sm font-semibold text-slate-800">Assign to</label>
                            <select id="assign-consultant" name="assigned_to_user_id" class="mt-1 block w-full rounded-xl border-slate-300">
                                <option value="">Unassigned queue</option>
                                @foreach ($consultants as $consultant)
                                    <option value="{{ $consultant->id }}" @selected((int) old('assigned_to_user_id', $inquiry->assigned_to_user_id) === $consultant->id)>{{ $consultant->name }} ({{ $consultant->role->label() }})</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('assigned_to_user_id')" class="mt-1" />
                        </div>
                        <div>
                            <label for="assign-reason" class="block text-sm font-semibold text-slate-800">Handover note <span class="font-normal text-slate-500">(optional)</span></label>
                            <input id="assign-reason" name="reason" type="text" minlength="5" maxlength="500" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Save assignment</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-flight-status">
                <h2 id="admin-flight-status" class="text-lg font-black text-slate-950">Status</h2>
                @if ($allowedTransitions->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $inquiry->status->label() }} has no transition available to your role.
                        @unless ($canReopen)
                            Reopening a resolved enquiry is restricted to managers and super administrators.
                        @endunless
                    </p>
                @else
                    <form method="POST" action="{{ route('admin.flight-inquiries.transition', $inquiry) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="transition-status" class="block text-sm font-semibold text-slate-800">Move to</label>
                            <select id="transition-status" name="status" required class="mt-1 block w-full rounded-xl border-slate-300">
                                @foreach ($allowedTransitions as $next)
                                    <option value="{{ $next->value }}" @selected(old('status') === $next->value)>{{ $next === FlightInquiryStatus::New ? 'Reopen (New)' : $next->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('status')" class="mt-1" />
                        </div>
                        <div>
                            <label for="transition-reason" class="block text-sm font-semibold text-slate-800">Reason <span class="font-normal text-slate-500">(required to close or cancel)</span></label>
                            <input id="transition-reason" name="reason" type="text" minlength="5" maxlength="1000" value="{{ old('reason') }}" class="mt-1 block w-full rounded-xl border-slate-300">
                            <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2 flex items-start gap-3">
                            <input id="notify-traveller" name="notify_traveller" type="checkbox" value="1" checked class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            <label for="notify-traveller" class="text-sm text-slate-700">Email the traveller about this change.</label>
                        </div>
                        <div class="sm:col-span-2">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Apply status change</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-flight-entry">
                <h2 id="admin-flight-entry" class="text-lg font-black text-slate-950">Record a note or contact</h2>
                <form method="POST" action="{{ route('admin.flight-inquiries.entries.store', $inquiry) }}" class="mt-4 grid gap-4 sm:grid-cols-3">
                    @csrf
                    <div>
                        <label for="entry-type" class="block text-sm font-semibold text-slate-800">Entry type</label>
                        <select id="entry-type" name="entry_type" required class="mt-1 block w-full rounded-xl border-slate-300">
                            @foreach ($entryTypes as $type)
                                <option value="{{ $type->value }}" @selected(old('entry_type') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('entry_type')" class="mt-1" />
                        <p class="mt-1 text-xs text-slate-500">Internal notes stay in this console. Communication entries also appear in the traveller's portal history.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="entry-body" class="block text-sm font-semibold text-slate-800">Details</label>
                        <textarea id="entry-body" name="body" rows="3" required minlength="3" maxlength="5000" class="mt-1 block w-full rounded-xl border-slate-300">{{ old('body') }}</textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Add entry</button>
                    </div>
                </form>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="admin-flight-history">
                <h2 id="admin-flight-history" class="text-lg font-black text-slate-950">Full history</h2>
                @if ($inquiry->entries->isEmpty())
                    <p class="mt-2 text-sm text-slate-600">No status changes, assignments, notes, or contacts recorded yet.</p>
                @else
                    <ol class="mt-4 space-y-3">
                        @foreach ($inquiry->entries as $entry)
                            <li @class([
                                'rounded-2xl border p-4 text-sm',
                                'border-emerald-200 bg-emerald-50/50' => $entry->isCommunication(),
                                'border-slate-200' => ! $entry->isCommunication(),
                            ])>
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $entry->entry_type->label() }}</p>
                                <p class="mt-1 text-slate-800">{{ $entry->body }}</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $entry->created_at->timezone($timezone)->format('j M Y, H:i') }} · {{ $entry->author?->name ?? 'system' }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
