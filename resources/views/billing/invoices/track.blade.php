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

        <p class="text-center text-xs text-slate-500">
            Keep this link private. Anyone who has it can see this invoice.
        </p>
    </div>
@endsection
