@extends('layouts.public')

@php
    $title = 'Invoice '.$invoice->number;
    $description = 'View your PISFA invoice.';
@endphp

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-12 sm:px-6 lg:px-8">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
        @endif

        @include('billing.invoices.partials.body')

        <div class="flex justify-center">
            <a href="{{ route('invoices.track.print', $invoice->tracking_token) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"><x-icon name="printer" class="h-4 w-4" />Print or save as PDF</a>
        </div>

        <p class="text-center text-xs text-slate-500">
            Keep this link private. Anyone who has it can see this invoice.
        </p>
    </div>
@endsection
