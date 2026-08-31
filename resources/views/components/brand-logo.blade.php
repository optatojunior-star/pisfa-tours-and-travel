@props([
    'variant' => 'lockup',   // lockup | mark | stacked
    'size' => 'md',          // sm | md | lg
    'href' => null,
    'inverse' => false,      // for dark grounds
])

@php
    $markSize = match ($size) {
        'sm' => 'h-8 w-8',
        'lg' => 'h-14 w-14',
        default => 'h-11 w-11',
    };

    $wordSize = match ($size) {
        'sm' => 'text-base',
        'lg' => 'text-2xl',
        default => 'text-lg',
    };

    $wordColour = $inverse ? 'text-white' : 'text-brand-900';
    $tagColour = $inverse ? 'text-brand-100' : 'text-accent-800';

    $tag = 'div';
    $attrs = $attributes->class(['inline-flex items-center gap-3']);

    if ($href !== null) {
        $tag = 'a';
        $attrs = $attributes->class([
            'inline-flex items-center gap-3 rounded-control',
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-700 focus-visible:ring-offset-2',
        ])->merge(['href' => $href]);
    }
@endphp

<{{ $tag }} {{ $attrs }}>
    {{--
        The mark carries no alt text of its own: the wordmark beside it is real
        text, so describing the globe as well would have a screen reader announce
        the company name twice. On the mark-only variant it becomes the label.
    --}}
    <img src="{{ asset('images/logo-mark.png') }}"
         alt="{{ $variant === 'mark' ? 'PISFA Tours and Travels' : '' }}"
         width="512" height="512"
         class="{{ $markSize }} shrink-0 object-contain"
         @if ($variant !== 'mark') aria-hidden="true" @endif>

    @if ($variant !== 'mark')
        <span class="whitespace-nowrap leading-none">
            <span class="block {{ $wordSize }} font-extrabold tracking-tight {{ $wordColour }}">PISFA</span>
            <span class="mt-1 block whitespace-nowrap text-[0.625rem] font-semibold uppercase tracking-[0.14em] {{ $tagColour }}">
                Tour and travel
            </span>
        </span>
    @endif
</{{ $tag }}>
