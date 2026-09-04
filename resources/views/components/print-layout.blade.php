@props(['title' => 'Document', 'back' => null, 'brand' => null])

@php
    // Falls back to configuration so the component still renders a correct
    // header if it is ever used somewhere that has no settings loaded.
    $brand = $brand ?? app(\App\Services\Settings\SettingsRepository::class)->brand();
@endphp

{{--
    A page built to be printed.

    Not the PDF layout: that one is written for DomPDF, which supports no
    flexbox, no grid and no custom properties, so it is all tables. This is real
    HTML in a real browser, where "print to PDF" is one keystroke and works on a
    phone. The two exist for different readers and neither replaces the other.

    Everything that is not the document itself carries `no-print`, so what comes
    out of the printer is the document and nothing else — no buttons, no
    navigation, no "back to invoices".
--}}
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- A printed invoice that has been indexed and served to a stranger is a
         data leak, and a guest copy is reachable by token alone. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title }} · {{ $brand['name'] }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    @vite(['resources/css/app.css'])

    <style>
        /*
         * Print rules. `exact` keeps the header rule and the totals band from
         * being dropped by browsers that strip backgrounds to save ink — on a
         * financial document the shading is what separates the total from the
         * lines above it.
         */
        @media print {
            @page { margin: 14mm; }

            html, body {
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print { display: none !important; }

            .sheet {
                box-shadow: none !important;
                border: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                max-width: none !important;
            }

            /* A table row split across a page break is unreadable, and a
               heading stranded at the foot of a page is worse. */
            tr, .avoid-break { break-inside: avoid; page-break-inside: avoid; }
            h1, h2, h3 { break-after: avoid; page-break-after: avoid; }

            a[href]::after { content: ''; }
        }
    </style>
</head>
<body class="bg-ink-100 text-ink-900 antialiased">
    <div class="no-print sticky top-0 z-10 border-b border-ink-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-[210mm] flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div>
                <p class="text-sm font-bold text-ink-900">{{ $title }}</p>
                <p class="text-xs text-ink-500">
                    Use your browser's print dialogue &mdash; choose &ldquo;Save as PDF&rdquo; to keep a copy.
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($back)
                    <a href="{{ $back }}"
                       class="inline-flex min-h-11 items-center rounded-control border border-ink-300 px-4 text-sm font-bold text-ink-700 hover:bg-ink-50">
                        Back
                    </a>
                @endif
                <button type="button" onclick="window.print()"
                        class="inline-flex min-h-11 items-center gap-2 rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
                    <x-icon name="printer" class="h-4 w-4" />
                    Print or save as PDF
                </button>
            </div>
        </div>
    </div>

    <main class="px-4 py-6 sm:py-10">
        <article class="sheet mx-auto max-w-[210mm] rounded-card border border-ink-200 bg-white p-6 shadow-sm sm:p-10">
            {{ $slot }}
        </article>
    </main>
</body>
</html>
