@php
    use App\Enums\ConversationStatus;
    use App\Enums\MessageAuthorType;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $conversation->reference }}</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $conversation->contact_name }}</h1>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $conversation->channel->label() }}
                    @if ($conversation->contact_email) · {{ $conversation->contact_email }} @endif
                    @if ($conversation->contact_phone) · {{ $conversation->contact_phone }} @endif
                </p>
            </div>
            <a href="{{ route('admin.inbox.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Back to inbox
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @error('body')
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900" role="alert">{{ $message }}</div>
            @enderror

            @unless ($canSendOnChannel)
                <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="alert">
                    WhatsApp is not configured on this deployment, so a reply here will be recorded but not sent.
                    Set the WhatsApp credentials in the environment to enable sending.
                </p>
            @endunless

            <section class="rounded-2xl border border-slate-200 bg-white" aria-label="Conversation">
                <div class="space-y-4 p-5">
                    @foreach ($messages as $message)
                        @php
                            $mine = $message->author_type === MessageAuthorType::Customer;
                        @endphp
                        <article @class([
                            'rounded-2xl border p-4',
                            'border-slate-200 bg-slate-50' => $mine,
                            'border-emerald-200 bg-emerald-50 ml-auto max-w-[85%]' => ! $mine && ! $message->is_internal_note,
                            'border-amber-200 bg-amber-50' => $message->is_internal_note,
                        ])>
                            <header class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                <span class="font-bold uppercase tracking-wide text-slate-600">
                                    {{ $message->is_internal_note ? 'Internal note' : ($message->author?->name ?? $message->author_type->label()) }}
                                </span>
                                <span class="text-slate-500">
                                    <time datetime="{{ $message->created_at->toIso8601String() }}">
                                        {{ $message->created_at->setTimezone($timezone)->format('D j M, H:i') }}
                                    </time>
                                    @unless ($mine || $message->is_internal_note)
                                        · <span class="font-semibold text-{{ $message->delivery_status->tone() }}-700">{{ $message->delivery_status->label() }}</span>
                                    @endunless
                                </span>
                            </header>
                            <p class="mt-2 whitespace-pre-line text-sm text-slate-800">{{ $message->body }}</p>
                            @if ($message->failure_reason)
                                <p class="mt-2 text-xs font-semibold text-rose-700">{{ $message->failure_reason }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($conversation->acceptsMessages())
                    <form method="POST" action="{{ route('admin.inbox.reply', $conversation) }}" class="border-t border-slate-200 p-5">
                        @csrf
                        <label for="body" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Reply</label>
                        <textarea id="body" name="body" rows="4" required maxlength="{{ config('messaging.chat.max_message_length', 2000) }}"
                                  class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('body') }}</textarea>
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <label class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-700">
                                <input type="checkbox" name="internal_note" value="1" class="rounded border-slate-300 text-amber-600 focus:ring-amber-600">
                                Internal note — the customer never sees this
                            </label>
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                                Send
                            </button>
                        </div>
                    </form>
                @else
                    <p class="border-t border-slate-200 p-5 text-sm text-slate-600">
                        This conversation is closed. {{ $conversation->closure_reason }}
                    </p>
                @endif
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-slate-200 bg-white p-5" aria-label="Assignment">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600">Assigned to</h2>
                    <form method="POST" action="{{ route('admin.inbox.assign', $conversation) }}" class="mt-3 flex flex-col gap-3 sm:flex-row">
                        @csrf
                        <label for="assigned_to_user_id" class="sr-only">Assign this conversation to</label>
                        <select id="assigned_to_user_id" name="assigned_to_user_id" class="min-h-11 flex-1 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">Nobody</option>
                            @foreach ($assignees as $assignee)
                                <option value="{{ $assignee->id }}" @selected($conversation->assigned_to_user_id === $assignee->id)>{{ $assignee->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            Save
                        </button>
                    </form>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5" aria-label="Status">
                    <h2 class="text-sm font-bold uppercase tracking-wide text-slate-600">
                        Status — {{ $conversation->status->label() }}
                    </h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        @if (in_array(ConversationStatus::Resolved, $nextStatuses, true))
                            <form method="POST" action="{{ route('admin.inbox.resolve', $conversation) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                                    Mark resolved
                                </button>
                            </form>
                        @endif

                        @if (in_array(ConversationStatus::Open, $nextStatuses, true))
                            <form method="POST" action="{{ route('admin.inbox.reopen', $conversation) }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                    Reopen
                                </button>
                            </form>
                        @endif
                    </div>

                    @if (in_array(ConversationStatus::Closed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.inbox.close', $conversation) }}" class="mt-4 space-y-2">
                            @csrf
                            <label for="reason" class="block text-xs font-bold uppercase tracking-wide text-slate-600">
                                Close for good — a reply from the customer starts a new thread
                            </label>
                            <input id="reason" name="reason" required minlength="5" maxlength="255" placeholder="Why is this being closed?"
                                   class="w-full min-h-11 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-300 px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50">
                                Close conversation
                            </button>
                        </form>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
