@php
    $isEdit = isset($review);
    $subject = $isEdit ? ($review->reviewable?->name ?? 'your trip') : $booking->package_name_snapshot;
    $action = $isEdit
        ? route('portal.reviews.update', $review)
        : route('portal.reviews.store', $booking);
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Reviews</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $isEdit ? 'Edit your review' : 'Write a review' }}</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">Check your review.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ $action }}" class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                @csrf
                @if ($isEdit) @method('PATCH') @endif

                <p class="text-sm text-slate-600">You are reviewing <span class="font-bold text-slate-900">{{ $subject }}</span>.</p>

                <fieldset>
                    <legend class="block text-sm font-semibold text-slate-800">Your rating</legend>
                    <div class="mt-2 flex flex-wrap gap-4">
                        @foreach ([5, 4, 3, 2, 1] as $value)
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50">
                                <input type="radio" name="rating" value="{{ $value }}" required
                                       @checked((int) old('rating', $isEdit ? $review->rating : 5) === $value)
                                       class="size-4 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                <span class="text-sm font-semibold text-slate-800">{{ $value }}</span>
                                <x-star-rating :rating="$value" />
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('rating')" class="mt-1" />
                </fieldset>

                <div>
                    <label for="title" class="block text-sm font-semibold text-slate-800">Headline</label>
                    <input id="title" name="title" type="text" required minlength="3" maxlength="160"
                           value="{{ old('title', $isEdit ? $review->title : '') }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div>
                    <label for="body" class="block text-sm font-semibold text-slate-800">Your review</label>
                    <textarea id="body" name="body" rows="6" required minlength="20" maxlength="5000"
                              class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('body', $isEdit ? $review->body : '') }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">At least a couple of sentences, so it is useful to other travellers.</p>
                    <x-input-error :messages="$errors->get('body')" class="mt-1" />
                </div>

                <p class="rounded-xl bg-stone-100 p-4 text-sm text-slate-700">
                    Reviews are read by our team before they appear publicly. Your name is shown as a
                    first name and surname initial.
                </p>

                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">
                    {{ $isEdit ? 'Save and resubmit' : 'Submit review' }}
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
