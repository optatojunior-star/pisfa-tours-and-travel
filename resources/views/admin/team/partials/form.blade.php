@php
    $editing = $member !== null;
@endphp

<form method="POST"
      action="{{ $editing ? route('admin.team.update', $member) : route('admin.team.store') }}"
      enctype="multipart/form-data" class="space-y-6">
    @csrf
    @if ($editing) @method('PATCH') @endif

    @if ($errors->any())
        <div class="rounded-card border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert" tabindex="-1">
            <p class="font-bold">The profile was not saved.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="space-y-5 rounded-card border border-ink-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-black text-ink-950">Who they are</h2>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-semibold text-ink-800">Name</label>
                <input id="name" name="name" required maxlength="120" value="{{ old('name', $member?->name) }}"
                       class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">
            </div>
            <div>
                <label for="role_title" class="block text-sm font-semibold text-ink-800">Role</label>
                <input id="role_title" name="role_title" required maxlength="120"
                       value="{{ old('role_title', $member?->role_title) }}" placeholder="Head of Safaris"
                       class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">
            </div>
        </div>

        <div>
            <label for="summary" class="block text-sm font-semibold text-ink-800">
                One line <span class="font-normal text-ink-500">(optional)</span>
            </label>
            <textarea id="summary" name="summary" rows="2" maxlength="400"
                      class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">{{ old('summary', $member?->summary) }}</textarea>
            <p class="mt-1 text-xs text-ink-500">Shown under the name on the about page.</p>
        </div>

        <div>
            <label for="biography" class="block text-sm font-semibold text-ink-800">
                Longer profile <span class="font-normal text-ink-500">(optional)</span>
            </label>
            <textarea id="biography" name="biography" rows="6" maxlength="5000"
                      class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">{{ old('biography', $member?->biography) }}</textarea>
        </div>
    </section>

    <section class="space-y-5 rounded-card border border-ink-200 bg-white p-6 shadow-sm">
        <div>
            <h2 class="text-lg font-black text-ink-950">Contact</h2>
            {{-- Optional, and public once the profile is published. Deliberately
                 not carried over from a staff account: putting somebody's
                 personal number on the website should be a decision, not a
                 side effect of them having a login. --}}
            <p class="mt-1 text-sm text-ink-600">
                Both are optional, and both appear on the public page once this profile is published.
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-3">
            <div>
                <label for="email" class="block text-sm font-semibold text-ink-800">Email</label>
                <input id="email" name="email" type="email" maxlength="190" value="{{ old('email', $member?->email) }}"
                       class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">
            </div>
            <div>
                <label for="phone" class="block text-sm font-semibold text-ink-800">Phone</label>
                <input id="phone" name="phone" maxlength="40" value="{{ old('phone', $member?->phone) }}"
                       class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">
            </div>
            <div>
                <label for="sort_order" class="block text-sm font-semibold text-ink-800">Position</label>
                <input id="sort_order" name="sort_order" type="number" min="0" max="9999"
                       value="{{ old('sort_order', $member?->sort_order ?? 0) }}"
                       class="mt-1 block w-full rounded-control border-ink-300 focus:border-brand-600 focus:ring-brand-600">
                <p class="mt-1 text-xs text-ink-500">Lower numbers come first.</p>
            </div>
        </div>
    </section>

    <section class="rounded-card border border-ink-200 bg-white p-6 shadow-sm">
        <x-image-upload
            name="images"
            label="Photograph"
            :multiple="false"
            help="A head-and-shoulders picture works best. The most recent one uploaded is the one shown."
            :existing="$editing ? $member->photographs : null"
            :delete-route="$editing ? fn ($image) => route('admin.media.destroy', $image) : null" />
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ route('admin.team.index') }}"
           class="inline-flex min-h-11 items-center justify-center rounded-control border border-ink-300 px-5 text-sm font-bold text-ink-700">
            Cancel
        </a>
        <button type="submit" class="min-h-11 rounded-control bg-brand-700 px-6 text-sm font-bold text-white hover:bg-brand-800">
            {{ $editing ? 'Save profile' : 'Create profile' }}
        </button>
    </div>
</form>
