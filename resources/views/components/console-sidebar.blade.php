@php
    use App\Support\Navigation\ConsoleNavigation;

    $user = auth()->user();
    $groups = ConsoleNavigation::for($user);
    $roleLabel = $user->role?->label() ?? 'Team member';
@endphp

{{--
    The console sidebar.

    Thirty-eight links do not fit in a horizontal bar. They used to wrap onto
    two rows and push the page content down, and finding anything meant reading
    a wall of same-sized text. Grouped down the side they are scannable, the
    groups stay put while the list scrolls, and adding a fortieth link costs
    nothing.

    The menu itself comes from ConsoleNavigation, so this file renders a
    structure rather than hard-coding one.
--}}
<div x-data="{ open: false }" @keydown.escape.window="open = false">
    {{-- Mobile bar --}}
    <div class="sticky top-0 z-40 flex items-center gap-3 border-b border-ink-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
        <button type="button" @click="open = true"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-ink-300 text-ink-700 hover:bg-ink-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700"
                aria-label="Open the menu" :aria-expanded="open.toString()">
            <x-icon name="menu" class="h-5 w-5" />
        </button>

        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 rounded-control focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700">
            <img src="{{ asset('images/logo-mark.png') }}" alt="" aria-hidden="true" width="512" height="512" class="h-8 w-8 object-contain">
            <span class="text-sm font-extrabold tracking-tight text-brand-900">PISFA</span>
        </a>
    </div>

    {{-- Backdrop, mobile only --}}
    <div x-show="open" x-cloak x-transition.opacity @click="open = false"
         class="fixed inset-0 z-40 bg-ink-950/50 lg:hidden" aria-hidden="true"></div>

    <aside
        :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-ink-200 bg-white transition-transform duration-200 lg:sticky lg:top-0 lg:z-30 lg:h-screen lg:translate-x-0"
        aria-label="Console navigation">

        {{-- Brand --}}
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-ink-200 px-5 py-4">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-control focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700">
                <img src="{{ asset('images/logo-mark.png') }}" alt="" aria-hidden="true" width="512" height="512" class="h-9 w-9 shrink-0 object-contain">
                <span>
                    <span class="block text-sm font-extrabold leading-4 tracking-tight text-brand-900">PISFA</span>
                    <span class="mt-0.5 block text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-500">{{ $roleLabel }}</span>
                </span>
            </a>

            <button type="button" @click="open = false"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink-500 hover:bg-ink-100 lg:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700"
                    aria-label="Close the menu">
                <x-icon name="close" class="h-5 w-5" />
            </button>
        </div>

        {{--
            The scrolling region. Only this scrolls, so the brand above and the
            account block below stay reachable however long the menu grows.
        --}}
        <nav class="flex-1 overflow-y-auto overscroll-contain px-3 py-4">
            <a href="{{ route('dashboard') }}" @class([
                'mb-4 flex items-center gap-3 rounded-control px-3 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700',
                'bg-brand-50 text-brand-800' => request()->routeIs('dashboard'),
                'text-ink-700 hover:bg-ink-100' => ! request()->routeIs('dashboard'),
            ])>
                <x-icon name="home" class="h-5 w-5 shrink-0" />
                Dashboard
            </a>

            @foreach ($groups as $group)
                <div class="mb-5">
                    <p class="flex items-center gap-2 px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ink-500">
                        <x-icon :name="$group['icon']" class="h-4 w-4 shrink-0" />
                        {{ $group['label'] }}
                    </p>

                    <ul class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php($isActive = request()->routeIs($item['active']))
                            <li>
                                <a href="{{ route($item['route']) }}" @class([
                                    'block rounded-control px-3 py-2 text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700',
                                    'bg-brand-50 font-bold text-brand-800' => $isActive,
                                    'font-medium text-ink-600 hover:bg-ink-100 hover:text-ink-900' => ! $isActive,
                                ]) @if($isActive) aria-current="page" @endif>
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        {{-- Account --}}
        <div class="shrink-0 border-t border-ink-200 p-3">
            <div class="flex items-center gap-3 rounded-control px-3 py-2">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-100 text-sm font-bold text-brand-800" aria-hidden="true">
                    {{ str($user->name)->trim()->substr(0, 1)->upper() }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-ink-900">{{ $user->name }}</span>
                    <span class="block truncate text-xs text-ink-500">{{ $user->email }}</span>
                </span>
            </div>

            <div class="mt-1 space-y-0.5">
                <a href="{{ route('profile.edit') }}" class="block rounded-control px-3 py-2 text-sm font-medium text-ink-600 hover:bg-ink-100 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700">
                    Profile
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full rounded-control px-3 py-2 text-left text-sm font-medium text-ink-600 hover:bg-ink-100 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </aside>
</div>
