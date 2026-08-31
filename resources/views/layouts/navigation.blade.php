@php
    $user = auth()->user();
    $roleValue = $user->role?->value ?? 'staff';
    $roleLabel = $user->role?->label() ?? 'Team member';
    $initial = str($user->name)->trim()->substr(0, 1)->upper();
    // Customers only: one count per render, used by both nav variants.
    $unreadMessages = $roleValue === 'customer' ? $user->unreadNotifications()->count() : 0;
    $foundationItems = match ($roleValue) {
        'super_admin' => [],
        'manager' => [],
        'staff' => ['Customers'],
        'driver' => [],
        'customer' => [],
        default => ['Workspace'],
    };
@endphp

<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-emerald-900/10 bg-white/95 shadow-sm backdrop-blur" aria-label="Primary navigation">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex min-h-16 items-center justify-between py-3">
            <div class="flex min-w-0 items-center gap-8">
                <a href="{{ route('dashboard') }}" class="flex shrink-0 items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2" aria-label="PISFA dashboard">
                    <x-application-logo class="h-10 w-10 text-emerald-700" />
                    <span class="hidden sm:block">
                        <span class="block text-base font-bold leading-4 tracking-[0.16em] text-emerald-950">PISFA</span>
                        <span class="mt-1 block text-[10px] font-semibold uppercase tracking-[0.13em] text-slate-500">Operations</span>
                    </span>
                </a>

                <div class="hidden items-center gap-1 lg:flex">
                    <a href="{{ route('dashboard') }}" @class([
                        'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'bg-emerald-50 text-emerald-800' => request()->routeIs('dashboard'),
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('dashboard'),
                    ])>
                        Dashboard
                    </a>

                    <a href="{{ route('profile.security') }}" @class([
                        'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'bg-emerald-50 text-emerald-800' => request()->routeIs('profile.security'),
                        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('profile.security'),
                    ])>
                        Security
                    </a>

                    @if ($roleValue === 'driver' && Route::has('drivers.index'))
                <a href="{{ route('drivers.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('drivers.index'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('drivers.index'),
                ])>My jobs</a>
                <a href="{{ route('drivers.history') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('drivers.history'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('drivers.history'),
                ])>Trip history</a>
                <a href="{{ route('portal.expenses.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.expenses.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.expenses.*'),
                ])>My expenses</a>
                <a href="{{ route('portal.payslips.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.payslips.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.payslips.*'),
                ])>My payslips</a>
            @elseif (in_array($roleValue, ['super_admin', 'manager', 'staff'], true) && Route::has('admin.tours.index'))
                <a href="{{ route('admin.bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.bookings.*'),
                ])>All bookings</a>
                        <a href="{{ route('admin.bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.bookings.*'),
                        ])>All bookings</a>
                        <a href="{{ route('admin.tours.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.tours.*') || request()->routeIs('admin.tour-categories.*') || request()->routeIs('admin.tour-departures.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.tours.*') && ! request()->routeIs('admin.tour-categories.*') && ! request()->routeIs('admin.tour-departures.*'),
                        ])>Tours</a>
                        <a href="{{ route('admin.tour-bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.tour-bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.tour-bookings.*'),
                        ])>Tour bookings</a>
                        <a href="{{ route('admin.vehicles.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.vehicles.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.vehicles.*'),
                        ])>Vehicles</a>
                        <a href="{{ route('admin.fleet.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.fleet.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.fleet.*'),
                        ])>Fleet</a>
                        <a href="{{ route('admin.car-hire-bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.car-hire-bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.car-hire-bookings.*'),
                        ])>Hire bookings</a>
                        <a href="{{ route('admin.airport-transfer-bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.airport-transfer-bookings.*') || request()->routeIs('admin.airport-transfer-settings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.airport-transfer-bookings.*') && ! request()->routeIs('admin.airport-transfer-settings.*'),
                        ])>Transfers</a>
                        <a href="{{ route('admin.flight-inquiries.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.flight-inquiries.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.flight-inquiries.*'),
                        ])>Flights</a>
                        <a href="{{ route('admin.payments.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.payments.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.payments.*'),
                        ])>Payments</a>
                        <a href="{{ route('admin.vehicle-imports.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.vehicle-imports.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.vehicle-imports.*'),
                        ])>Imports</a>
                        <a href="{{ route('admin.inbox.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.inbox.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.inbox.*'),
                        ])>Inbox</a>
                        <a href="{{ route('admin.reviews.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.reviews.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.reviews.*'),
                        ])>Reviews</a>
                        <a href="{{ route('admin.quotations.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.quotations.*') || request()->routeIs('admin.quotation-requests.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.quotations.*') && ! request()->routeIs('admin.quotation-requests.*'),
                        ])>Quotations</a>
                        <a href="{{ route('admin.invoices.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.invoices.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.invoices.*'),
                        ])>Invoices</a>
                        <a href="{{ route('admin.reports.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.reports.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.reports.*'),
                        ])>Reports</a>
                        <a href="{{ route('admin.showroom.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.showroom.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.showroom.*'),
                        ])>Showroom</a>
                        <a href="{{ route('admin.accommodation.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.accommodation.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.accommodation.*'),
                        ])>Stays</a>
                        <a href="{{ route('admin.leasing.leases.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.leasing.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.leasing.*'),
                        ])>Leasing</a>
                        <a href="{{ route('admin.expenses.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.expenses.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.expenses.*'),
                        ])>Expenses</a>
                        <a href="{{ route('admin.corporate.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.corporate.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.corporate.*'),
                        ])>Corporate</a>
                        @if ($roleValue === 'super_admin')
                            <a href="{{ route('admin.payroll.index') }}" @class([
                                'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                                'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.payroll.*'),
                                'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.payroll.*'),
                            ])>Payroll</a>
                        @endif
                        <a href="{{ route('admin.posts.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.posts.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.posts.*'),
                        ])>Journal</a>
                    @elseif ($roleValue === 'customer' && Route::has('portal.bookings.index'))
                        <a href="{{ route('portal.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.index') || request()->routeIs('portal.activity') || request()->routeIs('portal.documents'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.index') && ! request()->routeIs('portal.activity') && ! request()->routeIs('portal.documents'),
                        ])>My PISFA</a>
                        <a href="{{ route('portal.groups.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.groups.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.groups.*'),
                        ])>Groups</a>
                        <a href="{{ route('portal.notifications.index') }}" @class([
                            'relative rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.notifications.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.notifications.*'),
                        ])>
                            Messages
                            @if ($unreadMessages > 0)
                                <span class="ml-1 rounded-full bg-emerald-700 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadMessages }}</span>
                                <span class="sr-only">unread</span>
                            @endif
                        </a>
                        <a href="{{ route('portal.bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.bookings.*'),
                        ])>My bookings</a>
                        <a href="{{ route('portal.car-hire-bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.car-hire-bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.car-hire-bookings.*'),
                        ])>My car hire</a>
                        <a href="{{ route('portal.airport-transfer-bookings.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.airport-transfer-bookings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.airport-transfer-bookings.*'),
                        ])>My transfers</a>
                        <a href="{{ route('portal.flight-inquiries.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.flight-inquiries.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.flight-inquiries.*'),
                        ])>My flights</a>
                        <a href="{{ route('portal.vehicle-imports.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.vehicle-imports.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.vehicle-imports.*'),
                        ])>My imports</a>
                        <a href="{{ route('portal.loyalty.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.loyalty.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.loyalty.*'),
                        ])>Loyalty</a>
                        <a href="{{ route('portal.reviews.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.reviews.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.reviews.*'),
                        ])>My reviews</a>
                        <a href="{{ route('portal.quotations.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.quotations.*') || request()->routeIs('portal.quotation-requests.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.quotations.*') && ! request()->routeIs('portal.quotation-requests.*'),
                        ])>My quotations</a>
                        <a href="{{ route('portal.invoices.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.invoices.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('portal.invoices.*'),
                        ])>My invoices</a>
                    @endif

                    @if ($roleValue === 'super_admin')
                        <a href="{{ route('admin.audit.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.audit.*') || request()->routeIs('admin.settings.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.audit.*') && ! request()->routeIs('admin.settings.*'),
                        ])>Audit &amp; settings</a>
                        <a href="{{ route('admin.operations.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.operations.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.operations.*'),
                        ])>Operations</a>
                        <a href="{{ route('admin.staff.index') }}" @class([
                            'rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.staff.*'),
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! request()->routeIs('admin.staff.*'),
                        ])>
                            Team &amp; access
                        </a>
                    @endif

                    @foreach ($foundationItems as $item)
                        <span class="group inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-400" aria-disabled="true" title="{{ $item }} is coming soon">
                            {{ $item }}
                            <span class="rounded-full bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-200">Soon</span>
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="hidden items-center gap-3 lg:flex">
                <div class="hidden text-right xl:block">
                    <p class="max-w-40 truncate text-sm font-semibold text-slate-800">{{ $user->name }}</p>
                    <p class="text-xs font-medium text-emerald-700">{{ $roleLabel }}</p>
                </div>

                <x-dropdown align="right" width="w-64" contentClasses="overflow-hidden bg-white py-1">
                    <x-slot name="trigger">
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white p-1.5 pr-2 text-sm text-slate-600 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" x-bind:aria-expanded="open" aria-haspopup="menu" aria-label="Open account menu">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-700 text-sm font-bold text-white">{{ $initial }}</span>
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.512a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="border-b border-slate-100 px-4 py-3">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</p>
                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ $user->email }}</p>
                            <span class="mt-2 inline-flex rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-700">{{ $roleLabel }}</span>
                        </div>
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-emerald-800 focus:bg-slate-50 focus:outline-none">
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="12" cy="8" r="4" /><path d="M4.5 21a7.5 7.5 0 0 1 15 0" />
                            </svg>
                            Profile &amp; preferences
                        </a>
                        <a href="{{ route('profile.security') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-emerald-800 focus:bg-slate-50 focus:outline-none">
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 3 5 6v5c0 4.6 2.7 8.1 7 10 4.3-1.9 7-5.4 7-10V6l-7-3Z" /><path d="m9.5 12 1.7 1.7 3.5-3.7" />
                            </svg>
                            Security &amp; two-factor
                        </a>
                        @if ($roleValue === 'super_admin')
                            <a href="{{ route('admin.staff.index') }}" class="flex items-center gap-3 px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 hover:text-emerald-800 focus:bg-slate-50 focus:outline-none">
                                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="9" cy="8" r="3" /><circle cx="17" cy="10" r="2.5" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0M14 20a4 4 0 0 1 7 0" />
                                </svg>
                                Team &amp; access
                            </a>
                        @endif
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-rose-50 hover:text-rose-700 focus:bg-rose-50 focus:outline-none">
                                <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path d="M10 17l5-5-5-5M15 12H3" /><path d="M15 4h3a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3h-3" />
                                </svg>
                                Sign out
                            </button>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <button type="button" @click="open = ! open" x-bind:aria-expanded="open" aria-controls="mobile-navigation" class="inline-flex items-center justify-center rounded-lg p-2 text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 lg:hidden">
                <span class="sr-only">Toggle navigation</span>
                <svg x-show="! open" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16" />
                </svg>
                <svg x-show="open" x-cloak class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" />
                </svg>
            </button>
        </div>
    </div>

    <div id="mobile-navigation" x-show="open" x-cloak x-transition class="border-t border-slate-200 bg-white lg:hidden">
        <div class="space-y-1 px-4 py-3 sm:px-6">
            <a href="{{ route('dashboard') }}" class="block rounded-lg bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-800">Dashboard</a>
            <a href="{{ route('profile.security') }}" @class([
                'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                'bg-emerald-50 text-emerald-800' => request()->routeIs('profile.security'),
                'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('profile.security'),
            ])>Security &amp; two-factor</a>
            @if (in_array($roleValue, ['super_admin', 'manager', 'staff'], true) && Route::has('admin.tours.index'))
                <a href="{{ route('admin.tours.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.tours.*') || request()->routeIs('admin.tour-categories.*') || request()->routeIs('admin.tour-departures.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.tours.*') && ! request()->routeIs('admin.tour-categories.*') && ! request()->routeIs('admin.tour-departures.*'),
                ])>Tours &amp; departures</a>
                <a href="{{ route('admin.tour-bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.tour-bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.tour-bookings.*'),
                ])>Tour bookings</a>
                <a href="{{ route('admin.vehicles.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.vehicles.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.vehicles.*'),
                ])>Vehicles &amp; rates</a>
                <a href="{{ route('admin.car-hire-bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.car-hire-bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.car-hire-bookings.*'),
                ])>Car-hire bookings</a>
                <a href="{{ route('admin.airport-transfer-bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.airport-transfer-bookings.*') || request()->routeIs('admin.airport-transfer-settings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.airport-transfer-bookings.*') && ! request()->routeIs('admin.airport-transfer-settings.*'),
                ])>Airport transfers</a>
                <a href="{{ route('admin.flight-inquiries.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.flight-inquiries.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.flight-inquiries.*'),
                ])>Flight enquiries</a>
                <a href="{{ route('admin.payments.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.payments.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.payments.*'),
                ])>Payments</a>
                <a href="{{ route('admin.vehicle-imports.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.vehicle-imports.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.vehicle-imports.*'),
                ])>Vehicle imports</a>
                <a href="{{ route('admin.inbox.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.inbox.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.inbox.*'),
                ])>Inbox</a>
                <a href="{{ route('admin.reviews.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.reviews.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.reviews.*'),
                ])>Reviews</a>
                <a href="{{ route('admin.quotation-requests.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.quotation-requests.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.quotation-requests.*'),
                ])>Quotation requests</a>
                <a href="{{ route('admin.quotations.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.quotations.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.quotations.*'),
                ])>Quotations</a>
                <a href="{{ route('admin.invoices.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.invoices.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.invoices.*'),
                ])>Invoices</a>
                <a href="{{ route('admin.reports.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.reports.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.reports.*'),
                ])>Reports</a>
                <a href="{{ route('admin.showroom.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.showroom.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.showroom.*'),
                ])>Showroom</a>
                <a href="{{ route('admin.accommodation.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.accommodation.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.accommodation.*'),
                ])>Stays</a>
                <a href="{{ route('admin.leasing.leases.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.leasing.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.leasing.*'),
                ])>Leasing</a>
                <a href="{{ route('admin.posts.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.posts.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.posts.*'),
                ])>Journal</a>
            @elseif ($roleValue === 'customer' && Route::has('portal.bookings.index'))
                <a href="{{ route('portal.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.index'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.index'),
                ])>My PISFA</a>
                <a href="{{ route('portal.activity') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.activity'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.activity'),
                ])>Everything</a>
                <a href="{{ route('portal.documents') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.documents'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.documents'),
                ])>My documents</a>
                <a href="{{ route('portal.notifications.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.notifications.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.notifications.*'),
                ])>Messages @if ($unreadMessages > 0)({{ $unreadMessages }})@endif</a>
                <a href="{{ route('portal.bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.bookings.*'),
                ])>My bookings</a>
                <a href="{{ route('portal.car-hire-bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.car-hire-bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.car-hire-bookings.*'),
                ])>My car hire</a>
                <a href="{{ route('portal.airport-transfer-bookings.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.airport-transfer-bookings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.airport-transfer-bookings.*'),
                ])>My transfers</a>
                <a href="{{ route('portal.flight-inquiries.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.flight-inquiries.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.flight-inquiries.*'),
                ])>My flight enquiries</a>
                <a href="{{ route('portal.vehicle-imports.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.vehicle-imports.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.vehicle-imports.*'),
                ])>My imports</a>
                <a href="{{ route('portal.loyalty.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.loyalty.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.loyalty.*'),
                ])>Loyalty &amp; referrals</a>
                <a href="{{ route('portal.reviews.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.reviews.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.reviews.*'),
                ])>My reviews</a>
                <a href="{{ route('portal.quotation-requests.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.quotation-requests.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.quotation-requests.*'),
                ])>My quotation requests</a>
                <a href="{{ route('portal.quotations.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.quotations.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.quotations.*'),
                ])>My quotations</a>
                <a href="{{ route('portal.invoices.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('portal.invoices.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('portal.invoices.*'),
                ])>My invoices</a>
            @endif
            @if ($roleValue === 'super_admin')
                <a href="{{ route('admin.audit.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.audit.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.audit.*'),
                ])>Audit trail</a>
                <a href="{{ route('admin.settings.edit') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.settings.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.settings.*'),
                ])>Settings</a>
                <a href="{{ route('admin.operations.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.operations.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.operations.*'),
                ])>Operations</a>
                <a href="{{ route('admin.staff.index') }}" @class([
                    'block rounded-lg px-3 py-2.5 text-sm font-semibold',
                    'bg-emerald-50 text-emerald-800' => request()->routeIs('admin.staff.*'),
                    'text-slate-700 hover:bg-slate-50' => ! request()->routeIs('admin.staff.*'),
                ])>Team &amp; access</a>
            @endif
            @foreach ($foundationItems as $item)
                <span class="flex cursor-not-allowed items-center justify-between rounded-lg px-3 py-2.5 text-sm font-medium text-slate-400" aria-disabled="true">
                    {{ $item }}
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-700">Coming soon</span>
                </span>
            @endforeach
        </div>

        <div class="border-t border-slate-200 px-4 py-4 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-700 font-bold text-white">{{ $initial }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</p>
                    <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                </div>
                <span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">{{ $roleLabel }}</span>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <a href="{{ route('profile.edit') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Profile</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</nav>
