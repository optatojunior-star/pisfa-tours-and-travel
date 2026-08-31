@php
    use App\Enums\SalesEnquiryStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Sales enquiry</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $enquiry->contact_name }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $enquiry->reference }}</p>
            </div>
            <a href="{{ route('admin.showroom.enquiries.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All enquiries
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center gap-3">
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                                'bg-amber-100 text-amber-900' => $enquiry->status->tone() === 'amber',
                                'bg-sky-100 text-sky-900' => $enquiry->status->tone() === 'sky',
                                'bg-emerald-100 text-emerald-900' => $enquiry->status->tone() === 'emerald',
                                'bg-rose-100 text-rose-900' => $enquiry->status->tone() === 'rose',
                            ])>{{ $enquiry->status->label() }}</span>
                            @if ($enquiry->isGuest())
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-800">Guest — no account</span>
                            @endif
                        </div>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Email</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    <a href="mailto:{{ $enquiry->contact_email }}" class="text-emerald-800 hover:underline">{{ $enquiry->contact_email }}</a>
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Phone</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    <a href="tel:{{ $enquiry->contact_phone }}" class="text-emerald-800 hover:underline">{{ $enquiry->contact_phone }}</a>
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Received</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $enquiry->created_at->timezone($timezone)->format('j M Y, H:i') }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Their offer</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $enquiry->formattedOffer() ?? 'None made' }}</dd>
                            </div>
                        </dl>

                        @if ($enquiry->message)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">What they said</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $enquiry->message }}</p>
                            </div>
                        @endif

                        @if ($enquiry->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closed:</span> {{ $enquiry->closure_reason }}
                            </p>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Internal notes</h2>
                        <p class="mt-1 text-sm text-slate-600">Never shown to the enquirer.</p>

                        @if ($enquiry->internal_notes)
                            <p class="mt-4 whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm text-slate-700">{{ $enquiry->internal_notes }}</p>
                        @else
                            <p class="mt-4 text-sm text-slate-500">Nothing recorded yet.</p>
                        @endif

                        <form method="POST" action="{{ route('admin.showroom.enquiries.note', $enquiry) }}" class="mt-5 space-y-3">
                            @csrf
                            <label for="note" class="block text-sm font-semibold">Add a note</label>
                            <textarea id="note" name="note" rows="3" required minlength="2" maxlength="2000"
                                      placeholder="Called — coming to see it on Saturday morning."
                                      class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600"></textarea>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Add note
                            </button>
                        </form>
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">The vehicle</h2>
                        @if ($enquiry->listing)
                            <p class="mt-3 font-bold text-slate-900">{{ $enquiry->listing->title }}</p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ $enquiry->listing->formattedAskingPrice() }} · {{ $enquiry->listing->status->label() }}
                            </p>
                            <a href="{{ route('admin.showroom.show', $enquiry->listing) }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Open the listing
                            </a>
                        @else
                            <p class="mt-3 text-sm text-slate-600">The listing has been removed.</p>
                        @endif
                    </div>

                    @if ($nextStatuses !== [])
                        <form method="POST" action="{{ route('admin.showroom.enquiries.advance', $enquiry) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Move it on</h2>
                            <div>
                                <label for="status" class="block text-sm font-semibold">Next step</label>
                                <select id="status" name="status" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    @foreach ($nextStatuses as $next)
                                        <option value="{{ $next->value }}">{{ $next->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="advance-note" class="block text-sm font-semibold">
                                    Reason <span class="font-normal text-slate-500">(required to mark it lost)</span>
                                </label>
                                <input id="advance-note" name="note" type="text" maxlength="255"
                                       placeholder="Bought a Prado elsewhere"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Update
                            </button>
                            <p class="text-xs text-slate-500">
                                A sale is recorded against the listing, not here — that marks this enquiry won and
                                closes the others on the same vehicle.
                            </p>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.showroom.enquiries.assign', $enquiry) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        @csrf
                        <h2 class="text-lg font-black text-slate-900">Who is on it</h2>
                        <div>
                            <label for="assigned_to_user_id" class="block text-sm font-semibold">Assigned to</label>
                            <select id="assigned_to_user_id" name="assigned_to_user_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">Nobody</option>
                                @foreach ($assignees as $person)
                                    <option value="{{ $person->getKey() }}" @selected($enquiry->assigned_to_user_id === $person->getKey())>{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            Save
                        </button>
                    </form>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
