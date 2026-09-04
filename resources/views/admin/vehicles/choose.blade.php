<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Vehicles</p>
            <h1 class="mt-1 text-2xl font-bold text-ink-950">What is this vehicle for?</h1>
            <p class="mt-1 text-sm text-ink-600">
                The three are kept apart on purpose. A hire car needs daily rates and a
                calendar, a car for sale needs an asking price and a buyer, and an import
                needs a shipment to follow. Filing one as another makes work later.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-5 md:grid-cols-3">
                {{-- For hire --}}
                <a href="{{ route('admin.vehicles.create') }}"
                   class="group flex flex-col rounded-card border border-ink-200 bg-white p-6 shadow-sm transition hover:border-brand-400 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-control bg-brand-50 text-brand-700">
                        <x-icon name="car" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-4 text-lg font-black text-ink-950 group-hover:text-brand-800">Car for hire</h2>
                    <p class="mt-2 flex-1 text-sm text-ink-600">
                        Goes into the hire fleet. You set daily rates, and customers book it
                        by date. Also the vehicle drivers are assigned to for transfers.
                    </p>
                    <span class="mt-4 text-sm font-bold text-brand-700">Add a hire vehicle &rarr;</span>
                </a>

                {{-- For sale --}}
                <a href="{{ route('admin.showroom.create') }}"
                   class="group flex flex-col rounded-card border border-ink-200 bg-white p-6 shadow-sm transition hover:border-brand-400 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-control bg-amber-50 text-amber-700">
                        <x-icon name="tag" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-4 text-lg font-black text-ink-950 group-hover:text-brand-800">Car for sale</h2>
                    <p class="mt-2 flex-1 text-sm text-ink-600">
                        Goes into the public showroom with an asking price. Buyers send
                        enquiries about it, which arrive under sales enquiries.
                    </p>
                    <span class="mt-4 text-sm font-bold text-brand-700">Add a showroom listing &rarr;</span>
                </a>

                {{--
                    Not a link. An import order is raised by the customer, who has to
                    say what they want and agree the quotation — there is no admin form
                    that can honestly create one, and offering a button that leads
                    nowhere would be worse than saying so.
                --}}
                <div class="flex flex-col rounded-card border border-dashed border-ink-300 bg-ink-50 p-6">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-control bg-white text-ink-500">
                        <x-icon name="ship" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-4 text-lg font-black text-ink-800">Car for import</h2>
                    <p class="mt-2 flex-1 text-sm text-ink-600">
                        An import starts with the customer telling us what to source, so it
                        is not created from here. Raise it with them on the public import
                        form, then quote it from the orders list.
                    </p>
                    <div class="mt-4 flex flex-col gap-1">
                        <a href="{{ route('admin.vehicle-imports.index') }}" class="text-sm font-bold text-brand-700 underline">
                            Open import orders
                        </a>
                        <a href="{{ route('vehicle-imports.create') }}" class="text-sm font-semibold text-ink-600 underline">
                            The customer's import form
                        </a>
                    </div>
                </div>
            </div>

            <p class="mt-6 text-sm text-ink-600">
                Already have one and want to find it?
                <a href="{{ route('admin.vehicles.index') }}" class="font-bold text-brand-700 underline">Hire fleet</a>,
                <a href="{{ route('admin.showroom.index') }}" class="font-bold text-brand-700 underline">showroom</a>, or
                <a href="{{ route('admin.vehicle-imports.index') }}" class="font-bold text-brand-700 underline">imports</a>.
            </p>
        </div>
    </div>
</x-app-layout>
