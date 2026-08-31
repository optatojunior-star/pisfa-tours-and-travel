{{-- rose-700 rather than red-600: 5.9:1 on white against 4.0:1, so the label
     stays readable for anyone with reduced colour vision. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-rose-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-rose-800 active:bg-rose-900 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-700 focus-visible:ring-offset-2']) }}>
    {{ $slot }}
</button>
