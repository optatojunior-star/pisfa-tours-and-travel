@php
    use App\Enums\ReviewStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Reputation</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Reviews</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Review counts">
                @foreach ([
                    ReviewStatus::Pending->value => ['Awaiting moderation', $counts['pending'], 'amber'],
                    ReviewStatus::Published->value => ['Published', $counts['published'], 'emerald'],
                    ReviewStatus::Unpublished->value => ['Unpublished', $counts['unpublished'], 'slate'],
                    ReviewStatus::Rejected->value => ['Rejected', $counts['rejected'], 'rose'],
                ] as $value => [$label, $count, $tone])
                    <a href="{{ route('admin.reviews.index', ['status' => $value]) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-amber-300 bg-amber-50 hover:bg-amber-100' => $tone === 'amber' && $count > 0,
                        'border-slate-200 bg-white hover:bg-slate-50' => $tone !== 'amber' || $count === 0,
                        'ring-2 ring-emerald-600' => $status?->value === $value,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $count }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="review-filters">
                <h2 id="review-filters" class="sr-only">Filter reviews</h2>
                <form method="GET" action="{{ route('admin.reviews.index') }}" class="grid gap-4 sm:grid-cols-3 sm:items-end">
                    <div class="sm:col-span-2">
                        <label for="review-q" class="block text-sm font-semibold">Search</label>
                        <input id="review-q" name="q" type="search" maxlength="100" value="{{ $search }}"
                               placeholder="Reference, headline or wording"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="review-status" class="block text-sm font-semibold">Status</label>
                        <select id="review-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All statuses</option>
                            @foreach (ReviewStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.reviews.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($reviews->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No reviews match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a different status or clear the search.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Customer reviews awaiting or completed moderation</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Rating</th>
                                <th scope="col" class="px-4 py-3">Review</th>
                                <th scope="col" class="px-4 py-3">Customer</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Submitted</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($reviews as $review)
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $review->reference }}</td>
                                    <td class="px-4 py-3"><x-star-rating :rating="$review->rating" /></td>
                                    <td class="max-w-sm px-4 py-3">
                                        <p class="font-semibold text-slate-900">{{ $review->title }}</p>
                                        <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $review->body }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-slate-800">{{ $review->customer?->name ?? 'Removed customer' }}</p>
                                        <p class="text-xs text-slate-500">{{ $review->customer?->email }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-3 py-1 text-xs font-bold',
                                            'bg-emerald-50 text-emerald-800' => $review->status === ReviewStatus::Published,
                                            'bg-amber-50 text-amber-900' => $review->status === ReviewStatus::Pending,
                                            'bg-rose-50 text-rose-800' => $review->status === ReviewStatus::Rejected,
                                            'bg-slate-100 text-slate-700' => $review->status === ReviewStatus::Unpublished,
                                        ])>{{ $review->status->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $review->created_at->timezone($timezone)->format('j M Y H:i') }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.reviews.show', $review) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $reviews->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
