@extends('layouts.public')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    @if (session('success'))
        <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
    @endif

    <div class="space-y-6">
        @include('vehicle-imports.partials.progress', ['order' => $order])
        @include('vehicle-imports.partials.timeline', ['timeline' => $timeline])

        <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-next">
            <h2 id="import-next" class="text-lg font-black text-slate-950">Paying for this import</h2>
            @if ($order->hasQuote())
                <p class="mt-2 text-sm text-slate-700">
                    Deposit and balance payments are made from a PISFA account, so the transaction is tied
                    to a verified identity and your receipts stay in one place. Create an account using
                    <strong>{{ $order->contact_email }}</strong> and our team will link this import to it.
                </p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <a href="{{ route('register') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-900">Create an account</a>
                    <a href="{{ route('contact') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-5 py-3 text-sm font-bold text-emerald-800 hover:bg-emerald-50">Contact our team</a>
                </div>
            @else
                <p class="mt-2 text-sm text-slate-700">No payment is due yet. We will email a quotation to {{ $order->contact_email }} once our sourcing team has priced your request.</p>
            @endif
        </section>

        <p class="text-center text-xs text-slate-500">Keep this tracking link private. Anyone who has it can see this import.</p>
    </div>
</div>
@endsection
