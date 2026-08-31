@extends('layouts.public')

@php
    $title = 'Terms and conditions';
    $description = 'Terms governing use of the PISFA Tours and Travels public website and inquiry forms.';
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Website terms</p>
            <h1 class="mt-4 text-4xl font-black sm:text-5xl">Terms and conditions</h1>
            <p class="mt-4 text-emerald-100">Last updated: 4 August 2026</p>
        </div>
    </section>

    <article class="mx-auto max-w-4xl space-y-10 px-4 py-16 leading-8 text-slate-700 sm:px-6 lg:px-8">
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Using this website</h2>
            <p class="mt-3">You may use this website to learn about PISFA and submit genuine inquiries. Do not misuse the forms, attempt unauthorized access, interfere with the website, submit malicious files or content, or impersonate another person.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Service catalogue</h2>
            <p class="mt-3">Service cards labelled “Available online” can be browsed and requested through this website. A request is not a reservation: availability and price are checked again before anything is confirmed.</p>
            <p class="mt-3">Cards labelled “Arranged with our team” describe services we provide but do not sell self-service, because they depend on agreed rates or terms set up by a person. Those cards lead to a quotation request, which we answer directly.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Inquiries and quotations</h2>
            <p class="mt-3">Submitting a contact or quotation-request form stores a request for review. It does not create a reservation, contract, payment obligation, or guaranteed price. A service becomes binding only after the parties receive and accept the applicable confirmed terms.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Accuracy and availability</h2>
            <p class="mt-3">We aim to keep public information clear and useful, but destinations, routes, operating conditions, supplier availability, schedules, and prices can change. Important details must be confirmed before travel or payment.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Privacy</h2>
            <p class="mt-3">Information submitted through the website is handled as described in the <a href="{{ route('privacy') }}" class="font-bold text-emerald-800 underline">privacy policy</a>.</p>
        </section>
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Questions</h2>
            <p class="mt-3">For questions about these website terms, use the <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">contact form</a>.</p>
        </section>
    </article>
@endsection
