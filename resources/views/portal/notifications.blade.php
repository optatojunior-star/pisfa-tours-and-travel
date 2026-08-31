<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">My PISFA</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Messages</h1>
            </div>
            <a href="{{ route('portal.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my portal</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600">
                    Everything PISFA has sent you. The same messages also go to your email address.
                </p>
                @if ($unread > 0)
                    <form method="POST" action="{{ route('portal.notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                            Mark all {{ $unread }} as read
                        </button>
                    </form>
                @endif
            </div>

            @if ($notifications->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No messages</h2>
                    <p class="mt-2 text-sm text-slate-600">Confirmations, quotations, and reminders will appear here.</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach ($notifications as $notification)
                        @php
                            $data = (array) $notification->data;
                            $isUnread = $notification->read_at === null;
                        @endphp
                        <li @class([
                            'rounded-2xl border p-5',
                            'border-emerald-300 bg-emerald-50' => $isUnread,
                            'border-slate-200 bg-white' => ! $isUnread,
                        ])>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-900">
                                        {{ $data['title'] ?? 'Message from PISFA' }}
                                        @if ($isUnread)
                                            <span class="ml-2 rounded-full bg-emerald-700 px-2 py-0.5 text-[10px] font-bold uppercase text-white">New</span>
                                        @endif
                                    </p>
                                    @if (filled($data['message'] ?? null))
                                        <p class="mt-1 text-sm text-slate-700">{{ $data['message'] }}</p>
                                    @endif
                                    @if (filled($data['reference'] ?? null))
                                        <p class="mt-1 font-mono text-xs text-slate-500">{{ $data['reference'] }}</p>
                                    @endif
                                </div>
                                <p class="text-xs text-slate-500">
                                    {{ $notification->created_at->timezone($timezone)->format('j M Y, H:i') }}
                                </p>
                            </div>

                            <form method="POST" action="{{ route('portal.notifications.read', $notification->id) }}" class="mt-4">
                                @csrf
                                <button type="submit" @class([
                                    'inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-bold',
                                    'bg-emerald-700 text-white hover:bg-emerald-800' => filled($data['url'] ?? null),
                                    'border border-slate-300 text-slate-700' => blank($data['url'] ?? null),
                                ])>
                                    {{ filled($data['url'] ?? null) ? 'Open' : ($isUnread ? 'Mark as read' : 'Read') }}
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
                <div>{{ $notifications->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
