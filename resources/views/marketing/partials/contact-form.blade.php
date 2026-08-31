<form method="POST" action="{{ route('contact.store') }}" class="rounded-3xl bg-white p-6 shadow-xl shadow-emerald-950/10 ring-1 ring-slate-200 sm:p-8">
    @csrf
    <input type="hidden" name="source" value="{{ $source }}">

    <div class="grid gap-6 sm:grid-cols-2">
        <div>
            <label for="contact-name" class="block text-sm font-bold text-slate-800">Full name</label>
            <input id="contact-name" name="name" type="text" autocomplete="name" required minlength="2" maxlength="120" value="{{ old('name') }}" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" @error('name', 'contact') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
            @error('name', 'contact')<p id="contact-name-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="contact-email" class="block text-sm font-bold text-slate-800">Email address</label>
            <input id="contact-email" name="email" type="email" autocomplete="email" required maxlength="254" value="{{ old('email') }}" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" @error('email', 'contact') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
            @error('email', 'contact')<p id="contact-email-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="contact-phone" class="block text-sm font-bold text-slate-800">Telephone <span class="font-normal text-slate-500">(optional)</span></label>
            <input id="contact-phone" name="phone" type="tel" autocomplete="tel" maxlength="30" value="{{ old('phone') }}" placeholder="+256 ..." class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" @error('phone', 'contact') aria-invalid="true" aria-describedby="contact-phone-error" @enderror>
            @error('phone', 'contact')<p id="contact-phone-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="contact-service" class="block text-sm font-bold text-slate-800">Service of interest <span class="font-normal text-slate-500">(optional)</span></label>
            <select id="contact-service" name="service" class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" @error('service', 'contact') aria-invalid="true" aria-describedby="contact-service-error" @enderror>
                <option value="">Choose a service</option>
                @foreach ($services as $slug => $service)
                    <option value="{{ $slug }}" @selected(old('service', $selectedService ?? null) === $slug)>{{ $service['name'] }}</option>
                @endforeach
            </select>
            @error('service', 'contact')<p id="contact-service-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="mt-6">
        <label for="contact-message" class="block text-sm font-bold text-slate-800">How can we help?</label>
        <textarea id="contact-message" name="message" rows="6" required minlength="20" maxlength="5000" placeholder="Tell us your dates, group size, destination, budget, or any special requirements." class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20" @error('message', 'contact') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
        @error('message', 'contact')<p id="contact-message-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    </div>

    <p class="mt-5 text-sm leading-6 text-slate-600">
        Submitting this form saves your request for the PISFA team to review. It does not create or confirm a booking.
    </p>

    <button type="submit" class="mt-6 inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-emerald-800 px-6 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:ring-offset-2 sm:w-auto">
        {{ $buttonText }}
    </button>
</form>
