@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Vehicle import</p>
                <h1 class="mt-1 font-mono text-2xl font-bold text-slate-950">{{ $order->reference }}</h1>
            </div>
            <a href="{{ route('portal.vehicle-imports.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my imports</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @include('vehicle-imports.partials.progress', ['order' => $order])

            <x-pay-now :payable="$order" />

            @include('vehicle-imports.partials.timeline', ['timeline' => $timeline])

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-documents">
                <h2 id="import-documents" class="text-lg font-black text-slate-950">Shipping documents</h2>
                @if ($order->documents->isEmpty())
                    <p class="mt-3 text-sm text-slate-600">Documents appear here as shipping and clearance progress.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($order->documents as $document)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-4 text-sm">
                                <div>
                                    <p class="font-semibold text-slate-900">{{ $document->category->label() }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $document->humanSize() }}</p>
                                </div>
                                <a href="{{ route('documents.show', $document) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Download</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-messages">
                <h2 id="import-messages" class="text-lg font-black text-slate-950">Messages</h2>
                @if ($order->messages->isEmpty())
                    <p class="mt-3 text-sm text-slate-600">No messages yet. Our team posts updates here.</p>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($order->messages as $message)
                            <li @class([
                                'rounded-2xl border p-4 text-sm',
                                'border-emerald-200 bg-emerald-50/50' => ! $message->from_customer,
                                'border-slate-200' => $message->from_customer,
                            ])>
                                <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $message->from_customer ? 'You' : ($message->author?->name ?? 'PISFA') }}</p>
                                <p class="mt-1 text-slate-800">{{ $message->body }}</p>
                                <p class="mt-2 text-xs text-slate-500">{{ $message->created_at->timezone($timezone)->format('j M Y, H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
