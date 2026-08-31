<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-control border border-ink-300 bg-white px-5 py-2.5 text-sm font-bold text-ink-700 shadow-sm transition hover:bg-ink-50 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 focus-visible:ring-offset-2']) }}>
    {{ $slot }}
</button>
