@php
    use App\Models\Setting;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Governance</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Settings</h1>
            </div>
            <a href="{{ route('admin.audit.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Audit trail</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6">
                <h2 class="text-sm font-black uppercase tracking-wide text-amber-900">Credentials are not here</h2>
                <p class="mt-2 text-sm leading-6 text-slate-800">
                    Payment provider keys, webhook secrets, and mail passwords live in environment variables and
                    cannot be set from this page. A settings screen that could hold an API key would defeat the
                    reason those values are kept out of the database in the first place.
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-800">
                    Every change below is recorded in the audit trail with its before and after value.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
                @csrf
                @method('PATCH')

                @foreach ($groups as $group => $definitions)
                    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="group-{{ $group }}">
                        <h2 id="group-{{ $group }}" class="text-lg font-black text-slate-950">{{ $definitions[0]->groupLabel() }}</h2>

                        <div class="mt-5 space-y-5">
                            @foreach ($definitions as $definition)
                                @php
                                    $field = $fieldName($definition->key);
                                    $current = $values[$definition->key] ?? null;
                                @endphp

                                @if ($definition->type === Setting::TYPE_BOOLEAN)
                                    <div class="rounded-2xl border border-slate-200 p-4">
                                        <label class="flex items-start gap-3">
                                            <input type="checkbox" name="{{ $field }}" value="1"
                                                   @checked((bool) old($field, $current))
                                                   class="mt-1 size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                            <span>
                                                <span class="block text-sm font-semibold text-slate-800">{{ $definition->label }}</span>
                                                <span class="mt-1 block text-xs text-slate-500">{{ $definition->description }}</span>
                                            </span>
                                        </label>
                                        <x-input-error :messages="$errors->get($field)" class="mt-1" />
                                    </div>
                                @else
                                    <div>
                                        <label for="{{ $field }}" class="block text-sm font-semibold text-slate-800">{{ $definition->label }}</label>
                                        <input id="{{ $field }}" name="{{ $field }}" type="text" maxlength="255"
                                               value="{{ old($field, $current) }}"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        <p class="mt-1 text-xs text-slate-500">{{ $definition->description }}</p>
                                        <x-input-error :messages="$errors->get($field)" class="mt-1" />
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach

                <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-6 py-3 text-sm font-bold text-white hover:bg-emerald-800">
                    Save settings
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
