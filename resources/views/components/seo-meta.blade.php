@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'type' => 'website',
    'noindex' => false,
    'publishedAt' => null,
])

@php
    $siteName = config('pisfa.company.name', 'PISFA Tours and Travels');
    $fullTitle = $title ? $title.' | '.$siteName : $siteName;

    $desc = $description ?? 'Tours and safaris, car hire, airport transfers, places to stay, '
        .'vehicle imports and cars for sale across Uganda. Planned with local knowledge by '
        .'PISFA Tours and Travels, Kampala.';

    // Trimmed to what search results and social cards actually show. Longer text
    // is not penalised, it is simply cut off mid-sentence, which reads badly.
    $desc = Str::limit(trim($desc), 155);

    $ogImage = $image ?? asset('images/logo.png');

    /*
     * The canonical URL drops the query string.
     *
     * /tours, /tours?page=2 and /tours?q=gorilla are the same page as far as a
     * search engine should be concerned. Pointing every variant at itself splits
     * the ranking signal across dozens of near-identical URLs.
     */
    $canonical = url()->current();
@endphp

<title>{{ $fullTitle }}</title>
<meta name="description" content="{{ $desc }}">
<link rel="canonical" href="{{ $canonical }}">

@if ($noindex)
    {{-- Search results, filtered listings and anything behind a form: real
         pages for a person, noise in an index. --}}
    <meta name="robots" content="noindex, follow">
@else
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
@endif

{{-- Open Graph: what WhatsApp, Facebook and LinkedIn show when the link is
     shared. WhatsApp matters most here — it is how most Ugandan customers pass
     a link to a friend. --}}
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:title" content="{{ $title ?? $siteName }}">
<meta property="og:description" content="{{ $desc }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:alt" content="{{ $siteName }}">
<meta property="og:locale" content="en_UG">

@if ($publishedAt)
    <meta property="article:published_time" content="{{ $publishedAt }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title ?? $siteName }}">
<meta name="twitter:description" content="{{ $desc }}">
<meta name="twitter:image" content="{{ $ogImage }}">
