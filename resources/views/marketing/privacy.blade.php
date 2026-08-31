@extends('layouts.public')

@php
    $title = 'Privacy policy';
    $description = 'How PISFA Tours and Travels handles information submitted through its public website.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Privacy</p>
            <h1 class="mt-4 text-4xl font-black sm:text-5xl">Privacy policy</h1>
            <p class="mt-4 text-emerald-100">Last updated: 4 August 2026</p>
        </div>
    </section>

    <article class="mx-auto max-w-4xl space-y-10 px-4 py-16 leading-8 text-slate-700 sm:px-6 lg:px-8">
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Information we collect</h2>
            <p class="mt-3">The current public website stores information you choose to submit through contact, quotation-request, and newsletter forms. This may include your name, email address, telephone number, service interest, and message.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">How we use it</h2>
            <p class="mt-3">We use submitted information to review and respond to requests, provide requested updates, maintain service records, protect the website, and improve how we communicate. A submitted inquiry is not treated as a confirmed booking.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Sharing and protection</h2>
            <p class="mt-3">We do not sell information submitted through these forms. Information may be handled by service providers that support website hosting, email, storage, or security, subject to appropriate controls. Access should be limited to people who need it for their work.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Retention and choices</h2>
            <p class="mt-3">We retain inquiries and subscriptions only as long as reasonably needed for service, legal, security, or record-keeping purposes. You may ask the PISFA team to correct contact details or stop newsletter messages by using the <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">contact form</a>.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Future services</h2>
            <p class="mt-3">This policy must be reviewed before transactional booking, payment, customer-account, identity-document, driver, chat, or tracking modules are launched. Those modules may require additional notices and controls.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Contact</h2>
            <p class="mt-3">Questions about privacy can be sent through the working <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">PISFA contact form</a>.</p>
        </section>
    </article>
@endsection
