<x-app-layout>
    <x-slot name="title">Account security</x-slot>

    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">Account protection</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Two-factor authentication</h1>
            </div>
            <a href="{{ route('profile.edit') }}" class="text-sm font-semibold text-emerald-800 underline decoration-emerald-300 underline-offset-4 hover:text-emerald-700">Back to profile</a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">
                    Your two-factor security settings were updated.
                </div>
            @endif

            @if (session('warning'))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-950" role="alert">
                    {{ session('warning') }}
                </div>
            @endif

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="two-factor-status-heading">
                <div class="border-b border-slate-200 bg-slate-50 px-5 py-5 sm:px-7">
                    <div class="flex items-start gap-4">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M12 3 4.5 6v5.5c0 4.8 3 7.8 7.5 9.5 4.5-1.7 7.5-4.7 7.5-9.5V6L12 3Z" />
                                <path d="m9.5 12 1.7 1.7 3.6-4" />
                            </svg>
                        </span>
                        <div>
                            <h2 id="two-factor-status-heading" class="font-bold text-slate-950">
                                {{ $enabled ? 'Two-factor authentication is active' : ($pendingConfirmation ? 'Finish authenticator setup' : 'Add a second sign-in step') }}
                            </h2>
                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                @if ($enabled)
                                    Sign-ins require a time-based code or one unused recovery code after your password.
                                @elseif ($pendingConfirmation)
                                    Scan the QR code, then confirm setup with a current code from your authenticator app.
                                @else
                                    An authenticator app helps protect your account even if someone learns your password.
                                @endif
                            </p>
                            @if ($required)
                                <span class="mt-3 inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-800">Required for your role</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="p-5 sm:p-7">
                    @if (! $enabled && ! $pendingConfirmation)
                        <form method="POST" action="{{ route('two-factor.enable') }}">
                            @csrf
                            <x-primary-button class="bg-emerald-800 hover:bg-emerald-700 focus:bg-emerald-700 focus:ring-emerald-600 active:bg-emerald-900">
                                {{ __('Set up authenticator') }}
                            </x-primary-button>
                        </form>
                    @elseif ($pendingConfirmation)
                        <div class="grid gap-7 md:grid-cols-[13rem_minmax(0,1fr)] md:items-start">
                            <div class="rounded-2xl border border-slate-200 bg-white p-3 [&_svg]:h-auto [&_svg]:w-full" aria-label="Authenticator QR code">
                                {!! $qrCodeSvg !!}
                            </div>

                            <div>
                                <ol class="list-decimal space-y-2 pl-5 text-sm leading-6 text-slate-600">
                                    <li>Open an authenticator app and scan the QR code.</li>
                                    <li>If scanning is unavailable, enter the setup key below.</li>
                                    <li>Enter the current six-digit code to finish.</li>
                                </ol>

                                <div class="mt-4 rounded-xl bg-slate-950 px-4 py-3 text-slate-100">
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Manual setup key</p>
                                    <code class="mt-1 block break-all font-mono text-sm">{{ $setupKey }}</code>
                                </div>

                                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-5 space-y-4">
                                    @csrf
                                    <div>
                                        <x-input-label for="code" :value="__('Authenticator code')" />
                                        <x-text-input
                                            id="code"
                                            class="mt-1 block w-full max-w-xs text-lg tracking-[0.22em]"
                                            type="text"
                                            name="code"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            pattern="[0-9]*"
                                            maxlength="8"
                                            required
                                            autofocus
                                        />
                                        <x-input-error :messages="$errors->confirmTwoFactorAuthentication->get('code')" class="mt-2" />
                                    </div>

                                    <x-primary-button class="bg-emerald-800 hover:bg-emerald-700 focus:bg-emerald-700 focus:ring-emerald-600 active:bg-emerald-900">
                                        {{ __('Confirm and activate') }}
                                    </x-primary-button>
                                </form>

                                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-4">
                                    @csrf
                                    <input type="hidden" name="force" value="1">
                                    <button type="submit" class="text-sm font-semibold text-slate-600 underline decoration-slate-300 underline-offset-4 hover:text-slate-900">Generate a new setup key</button>
                                </form>

                                @unless ($required)
                                    <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-3">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-semibold text-red-700 underline decoration-red-200 underline-offset-4 hover:text-red-600">Cancel setup</button>
                                    </form>
                                @endunless
                            </div>
                        </div>
                    @else
                        <div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                            <div>
                                <h3 class="font-bold text-slate-950">Recovery codes</h3>
                                <p class="mt-1 text-sm leading-6 text-slate-600">Store these in a secure password manager. Each code can sign in once if your authenticator is unavailable.</p>
                                <div class="mt-4 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-4 font-mono text-sm sm:grid-cols-2">
                                    @foreach ($recoveryCodes as $recoveryCode)
                                        <code class="rounded-md bg-white px-3 py-2 text-slate-800 shadow-sm">{{ $recoveryCode }}</code>
                                    @endforeach
                                </div>
                            </div>

                            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                                @csrf
                                <x-secondary-button type="submit">
                                    {{ __('Generate new codes') }}
                                </x-secondary-button>
                            </form>
                        </div>

                        @unless ($required)
                            <div class="mt-7 border-t border-slate-200 pt-6">
                                <h3 class="font-bold text-slate-950">Turn off two-factor authentication</h3>
                                <p class="mt-1 text-sm text-slate-600">Your password will become the only sign-in factor.</p>
                                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-4">
                                    @csrf
                                    @method('DELETE')
                                    <x-danger-button>{{ __('Turn off two-factor authentication') }}</x-danger-button>
                                </form>
                            </div>
                        @endunless
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
