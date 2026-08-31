<x-guest-layout>
    @if ($invitation)
        <div class="text-center">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700" aria-hidden="true">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
            </span>
            <p class="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Private team invitation</p>
            <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Welcome, {{ str($invitation->user->name)->before(' ') }}</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">Set a strong password to activate your {{ $invitation->user->role->label() }} account for PISFA Tours and Travels.</p>
        </div>

        @if ($errors->any())
            <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <dl class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="break-all text-right font-semibold text-slate-800">{{ $invitation->user->email }}</dd></div>
            <div class="mt-2 flex justify-between gap-4"><dt class="text-slate-500">Role</dt><dd class="font-semibold text-slate-800">{{ $invitation->user->role->label() }}</dd></div>
            <div class="mt-2 flex justify-between gap-4"><dt class="text-slate-500">Expires</dt><dd class="text-right font-semibold text-slate-800">{{ $invitation->expires_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</dd></div>
        </dl>

        <form method="POST" action="{{ route('staff-invitations.accept', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf
            <div>
                <x-input-label for="password" value="Choose a password" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autofocus autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="password_confirmation" value="Confirm password" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            </div>
            <x-primary-button class="w-full justify-center">Activate my account</x-primary-button>
        </form>

        @if ($invitation->user->two_factor_required)
            <p class="mt-5 rounded-xl bg-violet-50 px-4 py-3 text-xs leading-5 text-violet-800">For your role, you will set up two-factor authentication immediately after activation.</p>
        @endif
    @else
        <div class="text-center">
            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 text-rose-700" aria-hidden="true">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6m0-6-6 6"/></svg>
            </span>
            <h1 class="mt-5 text-2xl font-bold tracking-tight text-slate-950">Invitation unavailable</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">This link is invalid, expired, already used, or has been replaced. Ask a PISFA administrator to send a new invitation.</p>
            <a href="{{ route('login') }}" class="mt-6 inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Go to sign in</a>
        </div>
    @endif
</x-guest-layout>
