@php
    $sections = [
        ['Before', $entry->old_values],
        ['After', $entry->new_values],
        ['Context', $entry->context],
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Governance</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $entry->event }}</h1>
            </div>
            <a href="{{ route('admin.audit.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to the trail</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="entry-heading">
                <h2 id="entry-heading" class="text-lg font-black text-slate-950">What happened</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">When</dt>
                        <dd class="mt-0.5 text-slate-800">
                            {{ $entry->created_at->timezone($timezone)->format('j F Y, H:i:s') }} ({{ $timezone }})
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Who</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $entry->user?->name ?? 'System or guest' }}</dd>
                        @if ($entry->user)
                            <dd class="text-xs text-slate-500">{{ $entry->user->email }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Record</dt>
                        <dd class="mt-0.5 text-slate-800">
                            @if ($entry->auditable_type)
                                {{ class_basename($entry->auditable_type) }} #{{ $entry->auditable_id }}
                                @if ($entry->auditable === null)
                                    <span class="text-xs italic text-slate-500">(since removed)</span>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">From</dt>
                        <dd class="mt-0.5 font-mono text-xs text-slate-700">{{ $entry->ip_address ?? '—' }}</dd>
                        @if ($entry->user_agent)
                            <dd class="mt-1 break-all text-xs text-slate-500">{{ $entry->user_agent }}</dd>
                        @endif
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">URL</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-slate-700">{{ $entry->url ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            @foreach ($sections as [$label, $values])
                @if (filled($values))
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="section-{{ str($label)->slug() }}">
                        <h2 id="section-{{ str($label)->slug() }}" class="text-lg font-black text-slate-950">{{ $label }}</h2>
                        <div class="mt-4 overflow-x-auto rounded-2xl bg-stone-50 p-4">
                            <pre class="whitespace-pre-wrap break-all text-xs text-slate-800">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </section>
                @endif
            @endforeach

            <p class="rounded-2xl bg-stone-100 p-4 text-xs leading-5 text-slate-600">
                Values matching a sensitive key pattern — passwords, tokens, identity document numbers, contact
                details — are replaced with <span class="font-mono">[REDACTED]</span> before the entry is written,
                so the trail records that something changed without becoming a second copy of what changed.
            </p>
        </div>
    </div>
</x-app-layout>
