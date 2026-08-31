<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">People &amp; access</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Invite a team member</h1>
            </div>
            <a href="{{ route('admin.staff.index') }}" class="text-sm font-semibold text-emerald-800 underline decoration-emerald-300 underline-offset-4 hover:text-emerald-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                Back to team access
            </a>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="invite-heading">
                <h2 id="invite-heading" class="text-xl font-bold text-slate-950">Account details</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">The account remains inactive until the recipient uses the private link and chooses a password. Invitation links expire after {{ config('pisfa.staff.invitation_expiry_hours', 72) }} hours.</p>

                @if ($errors->any())
                    <div class="mt-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                        <p class="font-semibold">Please correct the highlighted information.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.staff.store') }}" class="mt-7 space-y-6">
                    @csrf

                    <div>
                        <x-input-label for="name" value="Full name" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus autocomplete="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <x-input-label for="email" value="Work email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required autocomplete="email" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="phone" value="Phone (optional)" />
                            <x-text-input id="phone" name="phone" type="tel" class="mt-1 block w-full" :value="old('phone')" autocomplete="tel" placeholder="+256 ..." />
                            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="role" value="Access role" />
                        <select id="role" name="role" required class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                            <option value="">Select a role</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                        <p class="mt-2 text-xs leading-5 text-slate-500">Super administrators and managers must configure two-factor authentication before accessing protected tools.</p>
                    </div>

                    <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <input type="hidden" name="two_factor_required" value="0">
                        <input name="two_factor_required" value="1" type="checkbox" @checked(old('two_factor_required')) class="mt-1 rounded border-slate-300 text-emerald-700 shadow-sm focus:ring-emerald-500">
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">Require two-factor authentication</span>
                            <span class="mt-1 block text-xs leading-5 text-slate-500">Optional for staff and drivers; always enforced for managers and super administrators.</span>
                        </span>
                    </label>

                    <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:justify-end">
                        <a href="{{ route('admin.staff.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Cancel</a>
                        <x-primary-button>Send secure invitation</x-primary-button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
