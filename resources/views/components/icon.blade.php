@props(['name', 'class' => 'h-6 w-6'])

{{--
    Inline SVG icons, replacing the emoji this interface used to lean on.

    Emoji were a poor fit for three reasons. They render as a different picture
    on every platform, so a giraffe on Android is a different drawing from the
    one on Windows and neither matches the brand. They cannot take a colour, so
    they ignored the palette entirely. And screen readers announce their unicode
    name, which is why every one of them had to be hidden with aria-hidden and
    contributed nothing.

    These are stroked paths that inherit currentColor, so they take brand colours
    like any other element and stay crisp at any size.

    Paths are Heroicons (MIT, github.com/tailwindlabs/heroicons), 24x24 outline.
--}}
@php
    $paths = [
        // Services
        'compass' => '<circle cx="12" cy="12" r="9" /><path d="m14.8 9.2-1.6 4.4-4.4 1.6 1.6-4.4z" />',
        'car' => '<path d="M4.5 16.5h15M5.25 16.5v1.875a.75.75 0 0 1-.75.75h-.75a.75.75 0 0 1-.75-.75V16.5m15.75 0v1.875a.75.75 0 0 0 .75.75h.75a.75.75 0 0 0 .75-.75V16.5M3.75 16.5v-3.09c0-.38.096-.755.28-1.088l2.07-3.73A2.25 2.25 0 0 1 8.07 7.5h7.86a2.25 2.25 0 0 1 1.97 1.162l2.07 3.73c.184.333.28.707.28 1.088v3.09M7.5 13.5h.008v.008H7.5zm9 0h.008v.008H16.5z" />',
        'plane' => '<path d="M6 12 3.75 7.5V4.5l3 1.5L10.5 9l4.5-4.5a2.121 2.121 0 1 1 3 3L13.5 12l3 3.75 1.5 3-3 .001-4.5-2.251L6.75 21l-3 .001v-3l2.25-2.25z" />',
        'ship' => '<path d="M3 18.75c1.5 0 1.5-1.5 3-1.5s1.5 1.5 3 1.5 1.5-1.5 3-1.5 1.5 1.5 3 1.5 1.5-1.5 3-1.5 1.5 1.5 3 1.5M4.5 15l1.2-5.4A1.5 1.5 0 0 1 7.16 8.4h9.68a1.5 1.5 0 0 1 1.46 1.2L19.5 15M12 8.4V4.5M9.75 4.5h4.5" />',
        'tag' => '<path d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.1 18.1 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3" /><circle cx="7.5" cy="7.5" r="1" />',
        'home' => '<path d="m2.25 12 8.954-8.955a1.125 1.125 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />',
        'handshake' => '<path d="M12 6.75 9.75 4.5 6 8.25l3.75 3.75M12 6.75l2.25-2.25L18 8.25l-3.75 3.75M12 6.75v10.5M3 10.5h3M18 10.5h3M8.25 17.25h7.5" />',
        'building' => '<path d="M3.75 21h16.5M4.5 3h15v18h-15zM9 7.5h.008v.008H9zm3 0h.008v.008H12zm3 0h.008v.008H15zM9 11.25h.008v.008H9zm3 0h.008v.008H12zm3 0h.008v.008H15zM10.5 21v-3.75h3V21" />',
        // Values and reassurance
        'globe' => '<circle cx="12" cy="12" r="9" /><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18" />',
        'chat' => '<path d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />',
        'shield' => '<path d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-1.8 0A2.251 2.251 0 0 1 13.15 2.5m-1.8 1.336A48.774 48.774 0 0 0 6 5.055M9 12.75 11.25 15 15 9.75M12 21a9 9 0 0 0 7.5-8.876V6.11a1.5 1.5 0 0 0-1.06-1.434A48.7 48.7 0 0 0 12 3.75a48.7 48.7 0 0 0-6.44.926A1.5 1.5 0 0 0 4.5 6.11v6.014A9 9 0 0 0 12 21" />',
        'heart-hands' => '<path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12" />',
        'leaf' => '<path d="M6 21c0-9 6-15 15-15 0 9-6 15-15 15zm0 0c0-4.5 2.25-8.25 6-10.5" />',
        'pin' => '<path d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0" /><path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0" />',
        'check-circle' => '<circle cx="12" cy="12" r="9" /><path d="m9 12.75 2.25 2.25 4.5-4.5" />',
        'check' => '<path d="m4.5 12.75 6 6 9-13.5" />',
    ];

    $path = $paths[$name] ?? $paths['check-circle'];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    {!! $path !!}
</svg>
