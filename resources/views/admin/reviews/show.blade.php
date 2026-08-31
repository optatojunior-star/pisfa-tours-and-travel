@php
    use App\Enums\ReviewStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    // The form only ever offers transitions the action will actually accept, so
    // a moderator is never shown a button that fails validation.
    $transitions = $review->status->allowedTransitions();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Reputation</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Review {{ $review->reference }}</h1>
            </div>
            <a href="{{ route('admin.reviews.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">Back to reviews</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <x-star-rating :rating="$review->rating" />
                                <h2 class="mt-2 text-xl font-black text-slate-950">{{ $review->title }}</h2>
                            </div>
                            <span @class([
                                'rounded-full px-3 py-1 text-xs font-bold',
                                'bg-emerald-50 text-emerald-800' => $review->status === ReviewStatus::Published,
                                'bg-amber-50 text-amber-900' => $review->status === ReviewStatus::Pending,
                                'bg-rose-50 text-rose-800' => $review->status === ReviewStatus::Rejected,
                                'bg-slate-100 text-slate-700' => $review->status === ReviewStatus::Unpublished,
                            ])>{{ $review->status->label() }}</span>
                        </div>

                        <p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $review->body }}</p>

                        @if (filled($review->moderation_note))
                            <p class="mt-4 rounded-xl border border-slate-200 bg-stone-50 p-4 text-sm text-slate-700">
                                <span class="font-bold">Moderator note:</span> {{ $review->moderation_note }}
                            </p>
                        @endif
                    </article>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="review-reply">
                        <h2 id="review-reply" class="text-lg font-black text-slate-950">Public reply</h2>
                        @if ($review->hasReply())
                            <div class="mt-3 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                                <p class="text-sm text-slate-800">{{ $review->reply_body }}</p>
                                <p class="mt-2 text-xs text-slate-500">
                                    {{ $review->repliedBy?->name ?? 'PISFA' }}
                                    @if ($review->replied_at)
                                        · {{ $review->replied_at->timezone($timezone)->format('j M Y H:i') }}
                                    @endif
                                </p>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('admin.reviews.reply', $review) }}" class="mt-4 space-y-3">
                            @csrf
                            <label for="reply_body" class="block text-sm font-semibold text-slate-800">
                                {{ $review->hasReply() ? 'Update the reply' : 'Write a reply' }}
                            </label>
                            <textarea id="reply_body" name="reply_body" rows="4" required minlength="5" maxlength="2000"
                                      class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('reply_body', $review->reply_body) }}</textarea>
                            <p class="text-xs text-slate-500">Shown publicly beneath the review once it is published.</p>
                            <x-input-error :messages="$errors->get('reply_body')" />
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Save reply</button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="review-history">
                        <h2 id="review-history" class="text-lg font-black text-slate-950">Moderation history</h2>
                        <ol class="mt-4 space-y-3">
                            @foreach ($review->moderationEvents as $event)
                                <li class="rounded-2xl border border-slate-200 p-4 text-sm">
                                    <p class="font-semibold text-slate-900">{{ $event->action->label() }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ $event->actor?->name ?? 'System' }}
                                        · {{ $event->created_at->timezone($timezone)->format('j M Y H:i') }}
                                        @if ($event->from_status && $event->to_status)
                                            · {{ $event->from_status->label() }} → {{ $event->to_status->label() }}
                                        @endif
                                    </p>
                                    @if (filled($event->note))
                                        <p class="mt-2 text-sm text-slate-700">{{ $event->note }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="review-moderate">
                        <h2 id="review-moderate" class="text-lg font-black text-slate-950">Moderate</h2>
                        @if ($transitions === [])
                            <p class="mt-3 text-sm text-slate-600">No further moderation is possible from this status.</p>
                        @else
                            <form method="POST" action="{{ route('admin.reviews.moderate', $review) }}" class="mt-4 space-y-4">
                                @csrf
                                @method('PATCH')
                                <fieldset>
                                    <legend class="block text-sm font-semibold text-slate-800">New status</legend>
                                    <div class="mt-2 space-y-2">
                                        @foreach ($transitions as $target)
                                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                                <input type="radio" name="status" value="{{ $target->value }}" required
                                                       @checked(old('status') === $target->value)
                                                       class="size-4 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                                <span class="text-sm font-semibold text-slate-800">{{ $target->label() }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <x-input-error :messages="$errors->get('status')" class="mt-1" />
                                </fieldset>
                                <div>
                                    <label for="note" class="block text-sm font-semibold text-slate-800">Note</label>
                                    <textarea id="note" name="note" rows="3" minlength="5" maxlength="2000"
                                              class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('note') }}</textarea>
                                    <p class="mt-1 text-xs text-slate-500">Required when rejecting. The author sees this reason.</p>
                                    <x-input-error :messages="$errors->get('note')" class="mt-1" />
                                </div>
                                <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                            </form>
                        @endif
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="review-context">
                        <h2 id="review-context" class="text-lg font-black text-slate-950">Context</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</dt>
                                <dd class="text-slate-800">{{ $review->customer?->name ?? 'Removed customer' }}</dd>
                                <dd class="text-xs text-slate-500">{{ $review->customer?->email }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Shown publicly as</dt>
                                <dd class="text-slate-800">{{ $review->authorName() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Booking</dt>
                                <dd class="font-mono text-xs text-slate-700">{{ $review->booking?->reference ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Submitted</dt>
                                <dd class="text-slate-800">{{ $review->created_at->timezone($timezone)->format('j M Y H:i') }}</dd>
                            </div>
                            @if ($review->published_at)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Published</dt>
                                    <dd class="text-slate-800">{{ $review->published_at->timezone($timezone)->format('j M Y H:i') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
