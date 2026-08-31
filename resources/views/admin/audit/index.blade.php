<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Governance</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Audit trail</h1>
            </div>
            <a href="{{ route('admin.settings.edit') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Settings</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-slate-600">
                Who did what, and when. Entries are written by the application and are read-only — there is no
                route here that edits or deletes one. Sensitive values are redacted before an entry is stored.
            </p>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="audit-filters">
                <h2 id="audit-filters" class="sr-only">Filter the audit trail</h2>
                <form method="GET" action="{{ route('admin.audit.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="audit-q" class="block text-sm font-semibold">Search</label>
                        <input id="audit-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"
                               placeholder="Event, record type, or IP"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="audit-event" class="block text-sm font-semibold">Area</label>
                        <select id="audit-event" name="event" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Everything</option>
                            @foreach ($events as $prefix)
                                <option value="{{ $prefix }}" @selected(($filters['event'] ?? '') === $prefix)>{{ str($prefix)->replace('_', ' ')->headline() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="audit-user" class="block text-sm font-semibold">Who</label>
                        <select id="audit-user" name="user_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Anyone</option>
                            @foreach ($actors as $actor)
                                <option value="{{ $actor->id }}" @selected((int) ($filters['user_id'] ?? 0) === $actor->id)>{{ $actor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="audit-from" class="block text-sm font-semibold">From</label>
                        <input id="audit-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="audit-to" class="block text-sm font-semibold">To</label>
                        <input id="audit-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="flex gap-2 lg:col-span-5">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.audit.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>

                @if ($errors->any())
                    <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                        <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif
            </section>

            @if ($entries->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No entries match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a wider range, a different area, or clear the search.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Audit trail entries</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">When</th>
                                <th scope="col" class="px-4 py-3">Event</th>
                                <th scope="col" class="px-4 py-3">Who</th>
                                <th scope="col" class="px-4 py-3">Record</th>
                                <th scope="col" class="px-4 py-3">From</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($entries as $entry)
                                <tr class="align-top">
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        {{ $entry->created_at->timezone($timezone)->format('j M Y, H:i:s') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-slate-100 px-3 py-1 font-mono text-xs font-bold text-slate-700">{{ $entry->event }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($entry->user)
                                            <p class="text-slate-800">{{ $entry->user->name }}</p>
                                            <p class="text-xs text-slate-500">{{ $entry->user->email }}</p>
                                        @else
                                            {{-- A scheduled command or a guest action has no actor.
                                                 Saying so is more honest than attributing it. --}}
                                            <p class="text-xs italic text-slate-500">System or guest</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">
                                        @if ($entry->auditable_type)
                                            {{ class_basename($entry->auditable_type) }}
                                            <span class="text-slate-400">#{{ $entry->auditable_id }}</span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $entry->ip_address ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.audit.show', $entry) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $entries->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
