@php
    // Rendered on public pages. The whole widget is a single Alpine component
    // with no build step of its own, because Hostinger Premium runs no Node
    // process: what ships is what the browser gets.
    $pollSeconds = (int) config('messaging.chat.poll_seconds', 8);
    $maxLength = (int) config('messaging.chat.max_message_length', 2000);
@endphp

<div
    x-data="pisfaChat({
        startUrl: @js(route('chat.start')),
        pollSeconds: {{ $pollSeconds }},
        signedIn: @js(auth()->check()),
        name: @js(auth()->user()?->name),
        email: @js(auth()->user()?->email),
    })"
    x-cloak
    class="fixed bottom-4 right-4 z-50 print:hidden"
>
    <button
        type="button"
        x-show="!open"
        x-on:click="toggle()"
        class="inline-flex min-h-14 items-center gap-2 rounded-full bg-emerald-700 px-5 py-3 text-sm font-bold text-white shadow-lg transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
        aria-haspopup="dialog"
        :aria-expanded="open.toString()"
    >
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12Z" />
        </svg>
        Chat with us
    </button>

    <section
        x-show="open"
        x-transition.opacity
        class="flex h-[32rem] w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
        role="dialog"
        aria-modal="false"
        aria-labelledby="chat-widget-title"
    >
        <header class="flex items-center justify-between gap-3 bg-emerald-700 px-4 py-3 text-white">
            <div>
                <h2 id="chat-widget-title" class="text-sm font-bold">PISFA Tours and Travel</h2>
                <p class="text-xs text-emerald-100" x-text="statusLine">We usually reply within a few minutes.</p>
            </div>
            <button type="button" x-on:click="toggle()" class="rounded-lg p-2 hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                <span class="sr-only">Close the chat</span>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
        </header>

        {{-- Before the conversation exists: who is writing in. --}}
        <form x-show="!reference" x-on:submit.prevent="start()" class="flex-1 space-y-3 overflow-y-auto p-4">
            <p class="text-sm text-slate-600">
                Send us a message and we will reply here. We are open Monday to Friday, 8am to 6pm,
                and Saturday mornings.
            </p>

            <template x-if="!signedIn">
                <div class="space-y-3">
                    <div>
                        <label for="chat-name" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Your name</label>
                        <input id="chat-name" x-model="form.contact_name" required minlength="2" maxlength="180"
                               class="mt-1 w-full min-h-11 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="chat-email" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Email</label>
                        <input id="chat-email" type="email" x-model="form.contact_email" required maxlength="254"
                               class="mt-1 w-full min-h-11 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                </div>
            </template>

            <div>
                <label for="chat-first-message" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Message</label>
                <textarea id="chat-first-message" x-model="form.message" required rows="4" maxlength="{{ $maxLength }}"
                          class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600"></textarea>
            </div>

            <p x-show="error" x-text="error" class="rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-800" role="alert"></p>

            {{--
                The label is real text, not only an x-text binding. A button
                named solely by Alpine has no accessible name in the markup and
                none at all if the script fails to load; the binding replaces
                this text on hydration.
            --}}
            <button type="submit" :disabled="busy"
                    class="inline-flex w-full min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60">
                <span x-text="busy ? 'Sending…' : 'Start the chat'">Start the chat</span>
            </button>
        </form>

        {{-- Once it exists: the thread. --}}
        <div x-show="reference" class="flex flex-1 flex-col overflow-hidden">
            <div class="flex-1 space-y-3 overflow-y-auto p-4" x-ref="thread" aria-live="polite" aria-atomic="false">
                <template x-for="message in messages" :key="message.id">
                    <article
                        class="max-w-[85%] rounded-2xl px-3 py-2 text-sm"
                        :class="message.from_customer
                            ? 'ml-auto bg-emerald-700 text-white'
                            : 'bg-slate-100 text-slate-800'"
                    >
                        <p class="text-[0.65rem] font-bold uppercase tracking-wide opacity-70" x-text="message.author"></p>
                        <p class="mt-0.5 whitespace-pre-line" x-text="message.body"></p>
                    </article>
                </template>
            </div>

            <form x-on:submit.prevent="send()" class="border-t border-slate-200 p-3">
                <label for="chat-reply" class="sr-only">Your message</label>
                <div class="flex gap-2">
                    <input id="chat-reply" x-model="draft" required maxlength="{{ $maxLength }}" placeholder="Type a message"
                           class="min-h-11 flex-1 rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <button type="submit" :disabled="busy || !draft.trim()"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800 disabled:opacity-60">
                        Send
                    </button>
                </div>
                <p x-show="error" x-text="error" class="mt-2 text-xs font-semibold text-rose-700" role="alert"></p>
            </form>
        </div>
    </section>
</div>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('pisfaChat', (config) => ({
            open: false,
            busy: false,
            error: '',
            reference: null,
            cursor: null,
            messages: [],
            draft: '',
            timer: null,
            signedIn: config.signedIn,
            statusLine: 'We usually reply within a few minutes.',
            form: {
                contact_name: config.name ?? '',
                contact_email: config.email ?? '',
                message: '',
            },

            toggle() {
                this.open = !this.open;

                // Polling only runs while the panel is open. A background tab
                // quietly making a request every few seconds all day is exactly
                // the behaviour shared hosting punishes.
                this.open && this.reference ? this.startPolling() : this.stopPolling();
            },

            key() {
                return (crypto.randomUUID && crypto.randomUUID()) ||
                    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                        const r = (Math.random() * 16) | 0;
                        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
                    });
            },

            headers() {
                return {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                };
            },

            async start() {
                this.busy = true;
                this.error = '';

                try {
                    const response = await fetch(config.startUrl, {
                        method: 'POST',
                        headers: this.headers(),
                        body: JSON.stringify({ ...this.form, idempotency_key: this.key() }),
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        this.error = this.firstError(data);
                        return;
                    }

                    this.reference = data.reference;
                    this.messages = data.messages ?? [];
                    this.cursor = this.messages.at(-1)?.id ?? null;
                    this.form.message = '';
                    this.scroll();
                    this.startPolling();
                } catch {
                    this.error = 'We could not reach the site. Please check your connection and try again.';
                } finally {
                    this.busy = false;
                }
            },

            async send() {
                if (!this.draft.trim()) return;

                this.busy = true;
                this.error = '';
                const body = this.draft;

                try {
                    const response = await fetch(`/chat/${this.reference}/messages`, {
                        method: 'POST',
                        headers: this.headers(),
                        // The key is generated once per send, so a retry after a
                        // dropped connection cannot post the same sentence twice.
                        body: JSON.stringify({ body, idempotency_key: this.key() }),
                    });

                    const data = await response.json();

                    if (!response.ok) {
                        this.error = this.firstError(data);
                        return;
                    }

                    this.draft = '';
                    this.absorb(data.messages ?? []);
                } catch {
                    this.error = 'That did not send. Please try again.';
                } finally {
                    this.busy = false;
                }
            },

            async poll() {
                if (!this.reference) return;

                try {
                    const url = `/chat/${this.reference}/messages` +
                        (this.cursor ? `?after=${this.cursor}` : '');
                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });

                    if (!response.ok) return;

                    const data = await response.json();
                    this.absorb(data.messages ?? []);
                    this.statusLine = data.status === 'awaiting_customer'
                        ? 'We have replied — over to you.'
                        : 'We usually reply within a few minutes.';
                } catch {
                    // A failed poll is not worth an error message: the next one
                    // is a few seconds away, and the customer can still type.
                }
            },

            absorb(incoming) {
                const known = new Set(this.messages.map((m) => m.id));
                incoming.filter((m) => !known.has(m.id)).forEach((m) => this.messages.push(m));
                this.cursor = this.messages.at(-1)?.id ?? this.cursor;
                this.scroll();
            },

            firstError(data) {
                const errors = data?.errors ?? {};
                const first = Object.values(errors)[0];
                return (Array.isArray(first) ? first[0] : first) ??
                    data?.message ?? 'Something went wrong. Please try again.';
            },

            scroll() {
                this.$nextTick(() => {
                    const thread = this.$refs.thread;
                    if (thread) thread.scrollTop = thread.scrollHeight;
                });
            },

            startPolling() {
                this.stopPolling();
                this.timer = setInterval(() => this.poll(), config.pollSeconds * 1000);
            },

            stopPolling() {
                if (this.timer) clearInterval(this.timer);
                this.timer = null;
            },

            destroy() {
                this.stopPolling();
            },
        }));
    });
</script>
@endpush
