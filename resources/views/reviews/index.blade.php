@php
    use App\Enums\ReviewStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Reviews</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">My reviews</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($reviewable->isNotEmpty())
                <section class="rounded-3xl border border-emerald-200 bg-emerald-50/50 p-6 shadow-sm" aria-labelledby="reviews-invite">
                    <h2 id="reviews-invite" class="text-lg font-black text-slate-950">Tell us how it went</h2>
                    <p class="mt-2 text-sm text-slate-700">These trips are complete and have not been reviewed yet.</p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($reviewable as $booking)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-white p-4 text-sm">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $booking->package_name_snapshot }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $booking->reference }}</p>
                                </div>
                                <a href="{{ route('portal.reviews.create', $booking) }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Write a review</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($reviews->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No reviews yet</h2>
                    <p class="mt-2 text-sm text-slate-600">After a completed trip you can share how it went.</p>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($reviews as $review)
                        <article class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <x-star-rating :rating="$review->rating" />
                                    <h2 class="mt-2 text-lg font-black text-slate-950">{{ $review->title }}</h2>
                                    <p class="mt-1 text-xs text-slate-500">{{ $review->created_at->timezone($timezone)->format('j M Y') }}</p>
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

                            @if ($review->status === ReviewStatus::Rejected && filled($review->moderation_note))
                                <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="status">
                                    <span class="font-bold">Not published:</span> {{ $review->moderation_note }}
                                </p>
                            @endif

                            @if ($review->hasReply())
                                <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Reply from PISFA</p>
                                    <p class="mt-1 text-sm text-slate-800">{{ $review->reply_body }}</p>
                                </div>
                            @endif

                            <div class="mt-5 flex flex-wrap gap-2">
                                @can('update', $review)
                                    <a href="{{ route('portal.reviews.edit', $review) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Edit</a>
                                @endcan
                                @can('delete', $review)
                                    <form method="POST" action="{{ route('portal.reviews.destroy', $review) }}"
                                          onsubmit="return confirm('Withdraw this review?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-rose-200 px-4 text-sm font-bold text-rose-700">Withdraw</button>
                                    </form>
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>
                <div>{{ $reviews->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
