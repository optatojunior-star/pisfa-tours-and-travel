@php
    use App\Enums\ConversationChannel;
    use App\Enums\ConversationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Communications</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Inbox</h1>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Conversation counts">
                @foreach (ConversationStatus::cases() as $case)
                    <a href="{{ route('admin.inbox.index', ['status' => $case->value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-slate-200 bg-white hover:bg-slate-50',
                        'ring-2 ring-emerald-600' => $status === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $counts[$case->value] ?? 0 }}</p>
                    </a>
                @endforeach
            </section>

            @if (($counts['unassigned'] ?? 0) > 0)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                    {{ $counts['unassigned'] }} open {{ Str::plural('conversation', $counts['unassigned']) }}
                    {{ $counts['unassigned'] === 1 ? 'has' : 'have' }} nobody looking after
                    {{ $counts['unassigned'] === 1 ? 'it' : 'them' }}.
                    <a class="underline" href="{{ route('admin.inbox.index', ['unassigned' => 1]) }}">Show them</a>
                </p>
            @endif

            <form method="GET" action="{{ route('admin.inbox.index') }}" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="q" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Search</label>
                    <input id="q" name="q" value="{{ $search }}" placeholder="Reference, name, email or number"
                           class="mt-1 w-full min-h-11 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div>
                    <label for="channel" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Channel</label>
                    <select id="channel" name="channel" class="mt-1 min-h-11 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                        <option value="">All</option>
                        @foreach (ConversationChannel::cases() as $case)
                            <option value="{{ $case->value }}" @selected($channel === $case)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" name="mine" value="1" @checked($mine) class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                    Mine
                </label>
                <label class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-700">
                    <input type="checkbox" name="show" value="all" @checked($showAll) class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-600">
                    Include closed
                </label>
                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                    Filter
                </button>
            </form>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                @if ($conversations->isEmpty())
                    <p class="p-8 text-center text-sm text-slate-600">No conversations match this view.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Conversations, oldest unanswered first</caption>
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th scope="col" class="px-4 py-3">Contact</th>
                                    <th scope="col" class="px-4 py-3">Channel</th>
                                    <th scope="col" class="px-4 py-3">Status</th>
                                    <th scope="col" class="px-4 py-3">Waiting</th>
                                    <th scope="col" class="px-4 py-3">Assigned to</th>
                                    <th scope="col" class="px-4 py-3"><span class="sr-only">Open</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($conversations as $conversation)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3">
                                            <p class="font-bold text-slate-900">{{ $conversation->contact_name }}</p>
                                            <p class="text-xs text-slate-500">{{ $conversation->reference }}</p>
                                            @if ($conversation->subject)
                                                <p class="text-xs text-slate-600">{{ $conversation->subject }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full bg-{{ $conversation->channel->tone() }}-100 px-2.5 py-1 text-xs font-bold text-{{ $conversation->channel->tone() }}-800">
                                                {{ $conversation->channel->label() }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full bg-{{ $conversation->status->tone() }}-100 px-2.5 py-1 text-xs font-bold text-{{ $conversation->status->tone() }}-800">
                                                {{ $conversation->status->label() }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            @php($waiting = $conversation->waitingMinutes())
                                            @if ($waiting === null)
                                                <span class="text-slate-400">—</span>
                                            @else
                                                {{ $waiting < 60 ? $waiting.'m' : intdiv($waiting, 60).'h '.($waiting % 60).'m' }}
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-slate-700">
                                            {{ $conversation->assignee?->name ?? 'Nobody' }}
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('admin.inbox.show', $conversation) }}" class="font-bold text-emerald-700 underline">
                                                Open<span class="sr-only"> conversation {{ $conversation->reference }}</span>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{ $conversations->links() }}
        </div>
    </div>
</x-app-layout>
