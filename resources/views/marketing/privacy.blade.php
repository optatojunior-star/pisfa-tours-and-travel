@extends('layouts.public')

@php
    $title = 'Privacy policy';
    $description = 'How PISFA Tours and Travels collects, uses, shares and protects personal information, under the Uganda Data Protection and Privacy Act 2019.';

    // From the settings the invoices read, not from config, so the address
    // here can never contradict the one on a document the same customer is
    // holding.
    $company = app(\App\Services\Settings\SettingsRepository::class)->brand();
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Privacy</p>
            <h1 class="mt-4 text-4xl font-black sm:text-5xl">Privacy policy</h1>
            <p class="mt-4 max-w-2xl text-emerald-100">
                What we hold about you, why we hold it, who else sees it, and what you can ask us to do
                about it.
            </p>
            <p class="mt-4 text-sm text-emerald-200">
                Last updated: {{ \App\Support\LegalDocuments::LAST_UPDATED }}
            </p>
        </div>
    </section>

    <article class="mx-auto max-w-4xl space-y-10 px-4 py-16 leading-8 text-slate-700 sm:px-6 lg:px-8">
        <section>
            <h2 class="text-2xl font-black text-emerald-950">Who is responsible</h2>
            <p class="mt-3">
                {{ $company['legal_name'] }}
                @if (filled($company['registration_number']))
                    (registration number {{ $company['registration_number'] }}),
                @endif
                trading as {{ $company['name'] }}, of {{ $company['address'] }}, decides how and why
                your personal information is used, and is the data collector and processor for it under the
                Uganda Data Protection and Privacy Act 2019. You can reach us at
                <a href="mailto:{{ $company['email'] }}" class="font-bold text-emerald-800 underline">{{ $company['email'] }}</a>
                or on {{ $company['phone'] }}.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">What we collect</h2>
            <p class="mt-3">Only what a booking actually needs, and it varies by service:</p>
            <ul class="mt-4 space-y-3">
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span><strong>Everyone:</strong> your name, email address and telephone number, and what you asked us about.</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span><strong>Travellers on a tour:</strong> the names, nationalities and passport details required by the Uganda Wildlife Authority to issue permits, and anything you tell us about mobility or a medical condition that affects the itinerary.</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span><strong>Self-drive hirers:</strong> a copy of your driving permit and an identity document, which the law and our insurers require us to verify and keep.</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span><strong>People who pay us:</strong> a record of the payment, the method used, and a reference. We never store full payment card numbers &mdash; card payments are handled by the payment provider, and their systems hold those details, not ours.</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span><strong>Anyone using this site:</strong> the technical information a web server records &mdash; the pages requested, the time, and the browser used &mdash; which we use to keep the site working and secure.</span></li>
            </ul>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">Why we use it, and on what basis</h2>
            <p class="mt-3">
                Most of what we hold is used to perform the contract you have with us: arranging your trip,
                issuing quotations and invoices, and keeping you informed about a booking you have made. Some
                is held because the law requires it &mdash; tax records, and the driver verification our
                insurance is conditional on. A small amount is used for our legitimate interest in running the
                business safely, such as keeping an audit trail of who changed what.
            </p>
            <p class="mt-3">
                We send marketing only to people who have asked for it, and every such message carries a way to
                stop receiving them. Booking confirmations, reminders and invoices are not marketing and are
                sent whether or not you subscribe.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">Who else sees it</h2>
            <p class="mt-3">
                Your information is shared only with those who need it to deliver what you booked: the lodge or
                hotel you are staying at, the Uganda Wildlife Authority for permits, an airline where we are
                ticketing for you, our insurers, our payment providers, and our accountants and advisers. We do
                not sell personal information, and we do not share it for anyone else's marketing.
            </p>
            <p class="mt-3">
                Where the law requires disclosure &mdash; to the Uganda Revenue Authority, the police, or a court
                &mdash; we comply, and we tell you unless we are prohibited from doing so.
            </p>
            <p class="mt-3">
                Some of the services we rely on, such as email delivery and hosting, process data outside Uganda.
                Where that happens we satisfy ourselves that the information is protected to a comparable standard.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">How we protect it</h2>
            <p class="mt-3">
                Connections to this website are encrypted. Passwords are stored only as irreversible hashes,
                never as text. Documents you upload &mdash; a permit, an identity page &mdash; are held on private
                storage that is not reachable from the public web, and are served only to people authorised to see
                them. Staff accounts that can reach personal data are protected by two-factor authentication, and
                changes to records are recorded against the person who made them.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">How long we keep it</h2>
            <p class="mt-3">
                Booking and financial records are kept for the period Ugandan tax law requires, which is
                currently six years. Verification documents for a hire are kept for the duration of the hire and
                for as long afterwards as an insurance claim could still arise. Enquiries that do not become
                bookings are removed once they are plainly no longer live. Anything we no longer have a reason to
                hold is deleted.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">Your rights</h2>
            <p class="mt-3">Under the Data Protection and Privacy Act 2019 you may ask us to:</p>
            <ul class="mt-4 space-y-3">
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span>tell you what we hold about you, and give you a copy;</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span>correct anything that is wrong or out of date;</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span>delete what we no longer need to keep;</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span>stop using it for a particular purpose, including marketing;</span></li>
                <li class="flex gap-4"><span aria-hidden="true" class="mt-3 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                    <span>complain to the Personal Data Protection Office if you are not satisfied with how we have handled a request.</span></li>
            </ul>
            <p class="mt-4">
                Write to <a href="mailto:{{ $company['email'] }}" class="font-bold text-emerald-800 underline">{{ $company['email'] }}</a>
                and we will answer within thirty days. We may ask you to confirm who you are first &mdash; handing
                somebody's records to the wrong person is the failure this whole policy exists to prevent.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">Cookies</h2>
            <p class="mt-3">
                This site sets the cookies it needs to work: one that keeps you signed in, and one that protects
                forms against cross-site request forgery. Both are required for the site to function and cannot be
                turned off while you use it. We do not use advertising or cross-site tracking cookies.
            </p>
        </section>

        <section>
            <h2 class="text-2xl font-black text-emerald-950">Changes and questions</h2>
            <p class="mt-3">
                When this policy changes we update the date at the top of the page. Where a change materially
                affects how we use information we already hold, we tell affected customers directly.
            </p>
            <p class="mt-3">
                For anything to do with your information, use the
                <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">contact form</a>
                or write to us at the address above. See also the
                <a href="{{ route('booking-terms') }}" class="font-bold text-emerald-800 underline">booking terms</a>.
            </p>
        </section>
    </article>
@endsection
