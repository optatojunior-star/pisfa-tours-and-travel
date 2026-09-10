<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-brand-700">Content</p>
            <h1 class="mt-1 text-2xl font-bold text-ink-950">Service pictures</h1>
            <p class="mt-1 max-w-2xl text-sm text-ink-600">
                Each service shows a standard line icon. Upload a picture of your own and it takes
                that place on the homepage and the services list. Remove it and the icon comes back,
                so nothing here can leave a card blank.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-card border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('rejected'))
                <div class="rounded-card border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    <p class="font-bold">Some files were not accepted.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach (session('rejected') as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-card border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ($services as $key => $service)
                    @php
                        $row = $rows[$key] ?? null;
                        $url = $row?->imageUrl();
                    @endphp

                    <section class="flex flex-col overflow-hidden rounded-card border border-ink-200 bg-white shadow-sm">
                        <div class="flex items-center gap-4 border-b border-ink-200 p-4">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-control bg-brand-50">
                                @if ($url)
                                    <img src="{{ $url }}" alt="" class="h-full w-full object-cover">
                                @else
                                    <x-icon :name="$service['icon']" class="h-8 w-8 text-brand-700" />
                                @endif
                            </div>
                            <div class="min-w-0">
                                <h2 class="truncate font-black text-ink-950">{{ $service['name'] }}</h2>
                                <p class="text-xs text-ink-500">
                                    {{ $url ? 'Using your own picture' : 'Using the standard icon' }}
                                </p>
                            </div>
                        </div>

                        <form method="POST" action="{{ route('admin.service-images.store') }}"
                              enctype="multipart/form-data" class="flex flex-1 flex-col gap-3 p-4">
                            @csrf
                            <input type="hidden" name="service_key" value="{{ $key }}">

                            {{-- The shared uploader, with a distinct element id
                                 per card so the label, the input and the error
                                 belong to this service rather than the last one
                                 on the page. Menu pictures are shown at about
                                 48px square, so 640 is already generous — there
                                 is no reason to send a 6 MB phone photograph for
                                 an icon. --}}
                            <x-image-upload
                                name="images"
                                :input-id="'image-'.$key"
                                :label="$url ? 'Replace the picture' : 'Upload a picture'"
                                :max-edge="640"
                                help="A square-ish photograph works best — it is shown as a small tile." />

                            <div class="mt-auto flex flex-wrap gap-2 pt-2">
                                <button type="submit"
                                        class="min-h-11 rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800">
                                    Save picture
                                </button>
                            </div>
                        </form>

                        @if ($url)
                            <form method="POST"
                                  action="{{ route('admin.service-images.destroy', $row) }}"
                                  onsubmit="return confirm('Go back to the standard icon for this service?');"
                                  class="border-t border-ink-200 px-4 py-3">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-sm font-bold text-rose-700 underline">
                                    Use the standard icon instead
                                </button>
                            </form>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>
