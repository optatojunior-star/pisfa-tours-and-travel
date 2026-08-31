<x-guest-layout>
    <x-slot name="title">Two-factor verification</x-slot>

    <div class="mb-7">
        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-800" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M7 10V7a5 5 0 0 1 10 0v3" />
                <rect x="4" y="10" width="16" height="11" rx="2" />
                <path d="M12 14v3" />
            </svg>
        </div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Second verification step</p>
        <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">Confirm it is really you</h1>
        <p class="mt-3 text-sm leading-6 text-slate-600">Enter the current six-digit code from your authenticator app. This sign-in request expires after five minutes.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="code" :value="__('Authenticator code')" />
            <x-text-input
                id="code"
                class="mt-1 block w-full text-lg tracking-[0.25em]"
                type="text"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                pattern="[0-9]*"
                maxlength="8"
                required
                autofocus
            />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <x-primary-button class="w-full justify-center bg-emerald-800 hover:bg-emerald-700 focus:bg-emerald-700 focus:ring-emerald-600 active:bg-emerald-900">
            {{ __('Verify and continue') }}
        </x-primary-button>
    </form>

    <details class="mt-7 border-t border-slate-200 pt-5">
        <summary class="cursor-pointer text-sm font-semibold text-slate-700 hover:text-emerald-800">Use a recovery code instead</summary>
        <p class="mt-3 text-xs leading-5 text-slate-500">Each recovery code works once. Enter the complete code, including its hyphen.</p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-4 space-y-4">
            @csrf

            <div>
                <x-input-label for="recovery_code" :value="__('Recovery code')" />
                <x-text-input
                    id="recovery_code"
                    class="mt-1 block w-full font-mono"
                    type="text"
                    name="recovery_code"
                    autocomplete="one-time-code"
                    required
                />
                <x-input-error :messages="$errors->get('recovery_code')" class="mt-2" />
            </div>

            <x-secondary-button type="submit" class="w-full justify-center">
                {{ __('Use recovery code') }}
            </x-secondary-button>
        </form>
    </details>

    <p class="mt-7 text-center text-xs text-slate-500">
        <a href="{{ route('login') }}" class="font-semibold text-emerald-800 underline decoration-emerald-300 underline-offset-4 hover:text-emerald-700">Cancel and return to sign in</a>
    </p>
</x-guest-layout>
