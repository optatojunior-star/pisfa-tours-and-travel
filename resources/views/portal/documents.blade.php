@php
    use App\Services\Portal\CustomerDocumentQuery;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">My PISFA</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">My documents</h1>
            </div>
            <a href="{{ route('portal.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my portal</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-slate-600">
                Contracts, quotations, invoices, and receipts issued to you. Only the current version of each is
                listed; earlier versions are retained by PISFA but superseded.
            </p>

            @if ($documents->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No documents yet</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        Anything PISFA issues to you — a quotation, an invoice, a rental contract — will appear here.
                    </p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Documents issued to you</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Document</th>
                                <th scope="col" class="px-4 py-3">Relates to</th>
                                <th scope="col" class="px-4 py-3">Issued</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($documents as $document)
                                <tr>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800">{{ $document->category->label() }}</p>
                                        @if ($document->version > 1)
                                            <p class="text-xs text-slate-500">Version {{ $document->version }}</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ CustomerDocumentQuery::ownerLabel($document) }}</td>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        {{ $document->created_at->timezone($timezone)->format('j M Y') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        {{-- The download route re-checks authorisation through
                                             DocumentPolicy, which delegates to the owning record's
                                             own policy. Listing here grants nothing by itself. --}}
                                        <a href="{{ route('documents.show', $document) }}"
                                           class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">
                                            Download
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
