@php
    use App\Enums\QuotationRequestStatus;
    use App\Http\Controllers\Admin\QuotationRequestController;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $staff = QuotationRequestController::assignableStaff();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Quotation request</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $request->reference }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $request->serviceLabel() }}</p>
            </div>
            <a href="{{ route('admin.quotation-requests.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to requests</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
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
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="req-details">
                        <h2 id="req-details" class="text-lg font-black text-slate-950">What the customer asked for</h2>
                        <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-700">{{ $request->details }}</p>

                        <dl class="mt-5 grid gap-4 border-t border-slate-200 pt-5 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Preferred date</dt>
                                <dd class="mt-1 text-slate-800">{{ $request->preferred_date?->format('j M Y') ?? 'Flexible' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Party size</dt>
                                <dd class="mt-1 text-slate-800">{{ $request->party_size ?? 'Not stated' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Budget</dt>
                                <dd class="mt-1 text-slate-800">{{ $request->formattedBudget() ?? 'Not stated' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="req-quotations">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 id="req-quotations" class="text-lg font-black text-slate-950">Quotations</h2>
                            @if ($request->status->isOpen())
                                <a href="{{ route('admin.quotations.create', ['request' => $request->reference]) }}" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Price this request</a>
                            @endif
                        </div>

                        @if ($request->quotations->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">Nothing has been priced yet.</p>
                        @else
                            <ul class="mt-4 space-y-3">
                                @foreach ($request->quotations as $quotation)
                                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4 text-sm">
                                        <div>
                                            <a href="{{ route('admin.quotations.show', $quotation) }}" class="font-mono text-xs text-emerald-800 underline">{{ $quotation->number }}</a>
                                            <p class="mt-1 font-semibold text-slate-900">{{ $quotation->formattedTotal() }}</p>
                                        </div>
                                        <x-billing-status :status="$quotation->status" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="req-manage">
                        <h2 id="req-manage" class="text-lg font-black text-slate-950">Manage</h2>
                        <form method="POST" action="{{ route('admin.quotation-requests.update', $request) }}" class="mt-4 space-y-4">
                            @csrf
                            @method('PATCH')

                            <div>
                                <label for="assigned_to_user_id" class="block text-sm font-semibold text-slate-800">Owner</label>
                                <select id="assigned_to_user_id" name="assigned_to_user_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <option value="">Unassigned</option>
                                    @foreach ($staff as $member)
                                        <option value="{{ $member->id }}" @selected((int) old('assigned_to_user_id', $request->assigned_to_user_id) === $member->id)>{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('assigned_to_user_id')" class="mt-1" />
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-semibold text-slate-800">Status</label>
                                <select id="status" name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <option value="{{ $request->status->value }}">{{ $request->status->label() }} (unchanged)</option>
                                    @foreach ($request->status->allowedTransitions() as $target)
                                        <option value="{{ $target->value }}" @selected(old('status') === $target->value)>{{ $target->label() }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500">Only transitions the lifecycle allows are listed.</p>
                                <x-input-error :messages="$errors->get('status')" class="mt-1" />
                            </div>

                            <div>
                                <label for="closure_reason" class="block text-sm font-semibold text-slate-800">Closure reason</label>
                                <input id="closure_reason" name="closure_reason" type="text" minlength="5" maxlength="255"
                                       value="{{ old('closure_reason', $request->closure_reason) }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <p class="mt-1 text-xs text-slate-500">Required when closing or cancelling.</p>
                                <x-input-error :messages="$errors->get('closure_reason')" class="mt-1" />
                            </div>

                            <div>
                                <label for="internal_notes" class="block text-sm font-semibold text-slate-800">Internal notes</label>
                                <textarea id="internal_notes" name="internal_notes" rows="4" maxlength="5000"
                                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $request->internal_notes) }}</textarea>
                                <x-input-error :messages="$errors->get('internal_notes')" class="mt-1" />
                            </div>

                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">Save</button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="req-context">
                        <h2 id="req-context" class="text-lg font-black text-slate-950">Contact</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
                                <dd class="text-slate-800">{{ $request->contact_name }}</dd>
                            </div>
                            @if ($request->company_name)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Organisation</dt>
                                    <dd class="text-slate-800">{{ $request->company_name }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</dt>
                                <dd class="break-all text-slate-800">{{ $request->contact_email }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Phone</dt>
                                <dd class="text-slate-800">{{ $request->contact_phone }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account</dt>
                                <dd class="text-slate-800">{{ $request->customer?->name ?? 'Guest — no account' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Received</dt>
                                <dd class="text-slate-800">{{ $request->created_at->timezone($timezone)->format('j M Y H:i') }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
