@extends('layouts.public')

@php
    $title = 'Quotation request '.$request->reference;
    $description = 'Follow your PISFA quotation request.';
@endphp

@section('content')
    <div class="mx-auto max-w-4xl space-y-6 px-4 py-12 sm:px-6 lg:px-8">
        @if (session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
        @endif

        @include('billing.quotation-requests.partials.body')

        <p class="text-center text-xs text-slate-500">
            Keep this link private. Anyone who has it can see this request.
        </p>
    </div>
@endsection
