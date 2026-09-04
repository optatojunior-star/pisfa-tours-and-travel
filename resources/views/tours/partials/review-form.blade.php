{{--
    Leaving a review.

    Open to anybody reading the page. Reviews used to require a completed
    booking made by a registered customer, and most PISFA customers arrange
    their trip over WhatsApp and never make an account — so the moderation
    queue, the summary table and the whole public display existed with no way
    for an ordinary customer to put anything into them.

    It is not published on submission. That is said on the form rather than
    discovered afterwards when the review does not appear.
--}}
<section class="mt-8" aria-labelledby="write-review-heading">
    <h2 id="write-review-heading" class="text-2xl font-black text-emerald-950">Been on this trip?</h2>
    <p class="mt-2 text-sm text-slate-600">
        Tell other travellers how it went. We read every review before it goes up.
    </p>

    @if (session('review_submitted'))
        <div class="mt-5 rounded-3xl border border-emerald-200 bg-emerald-50 p-6" role="status">
            <p class="font-bold text-emerald-900">Thank you.</p>
            <p class="mt-1 text-sm text-emerald-800">
                Your review has reached us. It appears on this page once somebody has read it.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('tours.reviews.store', $package) }}"
              class="mt-5 space-y-5 rounded-3xl border border-slate-200 bg-white p-6">
            @csrf

            @if ($errors->any())
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <fieldset>
                <legend class="text-sm font-semibold text-slate-800">Your rating</legend>

                {{-- Radios, not stars drawn in JavaScript. This has to work on a
                     slow phone and in a screen reader, and a rating out of five
                     is exactly what a radio group already is. --}}
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ([5, 4, 3, 2, 1] as $star)
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="{{ $star }}" required
                                   @checked((int) old('rating') === $star)
                                   class="peer sr-only">
                            <span class="inline-flex min-h-11 items-center gap-1.5 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 peer-checked:border-emerald-600 peer-checked:bg-emerald-50 peer-checked:text-emerald-900 peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-600">
                                {{ $star }}
                                <span aria-hidden="true">&#9733;</span>
                                <span class="sr-only">out of five</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label for="review-title" class="block text-sm font-semibold text-slate-800">Headline</label>
                <input id="review-title" name="title" required minlength="3" maxlength="160"
                       value="{{ old('title') }}" placeholder="Worth every early morning"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>

            <div>
                <label for="review-body" class="block text-sm font-semibold text-slate-800">Your review</label>
                <textarea id="review-body" name="body" rows="5" required minlength="20" maxlength="5000"
                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('body') }}</textarea>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="review-name" class="block text-sm font-semibold text-slate-800">Your name</label>
                    <input id="review-name" name="guest_name" required minlength="2" maxlength="120"
                           value="{{ old('guest_name', auth()->user()?->name) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <p class="mt-1 text-xs text-slate-500">Shown as a first name and an initial, never in full.</p>
                </div>
                <div>
                    <label for="review-email" class="block text-sm font-semibold text-slate-800">Your email</label>
                    <input id="review-email" name="guest_email" type="email" required maxlength="190"
                           value="{{ old('guest_email', auth()->user()?->email) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    <p class="mt-1 text-xs text-slate-500">Never published. It is how we reach you if something went wrong.</p>
                </div>
            </div>

            <button type="submit"
                    class="min-h-11 rounded-xl bg-emerald-800 px-6 text-sm font-bold text-white hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2">
                Send my review
            </button>
        </form>
    @endif
</section>
