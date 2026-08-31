{{--
    The primary action.

    Sentence case, not the uppercase Breeze default. Uppercase costs legibility,
    and it meant the browser reported this button's text as "LOG IN" while the
    markup said "Log in" — which quietly broke anything matching on its label.

    min-h-11 is 44px, the minimum comfortable touch target.
--}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-brand-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-brand-900 active:bg-brand-950 disabled:cursor-not-allowed disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 focus-visible:ring-offset-2']) }}>
    {{ $slot }}
</button>
