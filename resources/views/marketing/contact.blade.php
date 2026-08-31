@extends('layouts.public')

@php
    $title = 'Contact';
    $description = 'Send a saved message to PISFA Tours and Travels for help with travel, transport, vehicles, or accommodation.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Contact PISFA</p>
            <h1 class="mt-4 max-w-3xl text-4xl font-black sm:text-5xl">Start with the details that matter to you.</h1>
            <p class="mt-5 max-w-2xl text-lg leading-8 text-emerald-100">Send a general message or ask about one of the planned service areas. Your submission is stored for team follow-up.</p>
        </div>
    </section>

    <section class="px-4 py-16 sm:px-6 lg:px-8">
        <div class="mx-auto grid max-w-7xl gap-10 lg:grid-cols-[0.7fr_1.3fr]">
            <aside class="rounded-3xl bg-amber-50 p-7 ring-1 ring-amber-100 lg:sticky lg:top-6 lg:self-start">
                <h2 class="text-xl font-black text-emerald-950">What happens next?</h2>
                <ol class="mt-6 space-y-5 text-sm leading-6 text-slate-700">
                    <li class="flex gap-4"><span class="font-black text-amber-700">1.</span><span>We securely save the details submitted through this form.</span></li>
                    <li class="flex gap-4"><span class="font-black text-amber-700">2.</span><span>The PISFA team reviews your request and its service area.</span></li>
                    <li class="flex gap-4"><span class="font-black text-amber-700">3.</span><span>A team member follows up using the email or telephone you provide.</span></li>
                </ol>
                <p class="mt-7 rounded-2xl bg-white p-4 text-sm leading-6 text-slate-600 ring-1 ring-amber-100">This form is for inquiries. It does not confirm availability, pricing, or a booking.</p>
            </aside>

            @include('marketing.partials.contact-form', [
                'source' => 'contact',
                'buttonText' => 'Send message',
                'selectedService' => null,
            ])
        </div>
    </section>
@endsection
