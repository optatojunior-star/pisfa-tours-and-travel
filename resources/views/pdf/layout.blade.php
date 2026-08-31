{{--
    Shared PDF shell.

    DomPDF supports only a limited CSS subset: no flexbox, no grid, no custom
    properties. Layout therefore uses tables and inline-ish styles deliberately,
    not out of neglect. Keep it that way or the PDF silently mis-renders.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', $brand['name'])</title>
    <style>
        @page { margin: 26mm 16mm 22mm 16mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #0f172a;
            margin: 0;
        }

        .header { border-bottom: 2.5pt solid #065f46; padding-bottom: 8pt; margin-bottom: 14pt; }
        .header td { vertical-align: top; }
        .brand-name { font-size: 17pt; font-weight: bold; color: #065f46; }
        .brand-tagline { font-size: 8.5pt; color: #475569; }
        .brand-contact { font-size: 8.5pt; color: #475569; text-align: right; }

        .doc-title { font-size: 14pt; font-weight: bold; color: #0f172a; margin: 0 0 2pt; }
        .doc-reference { font-family: DejaVu Sans Mono, monospace; font-size: 9.5pt; color: #065f46; }

        h2 {
            font-size: 10.5pt;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #065f46;
            border-bottom: 0.6pt solid #cbd5e1;
            padding-bottom: 3pt;
            margin: 16pt 0 7pt;
        }

        table { width: 100%; border-collapse: collapse; }

        table.data th {
            background: #f1f5f9;
            text-align: left;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #334155;
            padding: 5pt 6pt;
            border-bottom: 0.6pt solid #cbd5e1;
        }
        table.data td { padding: 5pt 6pt; border-bottom: 0.4pt solid #e2e8f0; }
        table.data td.num, table.data th.num { text-align: right; }

        table.pairs td { padding: 3pt 0; vertical-align: top; }
        table.pairs td.label { color: #64748b; width: 38%; font-size: 9.5pt; }
        table.pairs td.value { font-weight: bold; }

        .totals { margin-top: 10pt; }
        .totals td { padding: 4pt 6pt; }
        .totals tr.grand td {
            border-top: 1.2pt solid #0f172a;
            font-weight: bold;
            font-size: 12pt;
            color: #065f46;
        }

        .notice {
            background: #f8fafc;
            border-left: 2.5pt solid #f59e0b;
            padding: 8pt 10pt;
            margin-top: 14pt;
            font-size: 9pt;
            color: #334155;
        }

        .footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #64748b;
            border-top: 0.4pt solid #cbd5e1;
            padding-top: 5pt;
        }
        .footer td { vertical-align: top; }
        .footer .right { text-align: right; }

        .muted { color: #64748b; }
        .signature { margin-top: 26pt; }
        .signature td { padding-top: 26pt; border-bottom: 0.6pt solid #94a3b8; width: 44%; }
        .signature td.gap { border-bottom: none; width: 12%; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="brand-name">{{ $brand['name'] }}</div>
                <div class="brand-tagline">{{ $brand['tagline'] }}</div>
            </td>
            <td class="brand-contact">
                @if (filled($brand['address']))<div>{{ $brand['address'] }}</div>@endif
                @if (filled($brand['phone']))<div>{{ $brand['phone'] }}</div>@endif
                @if (filled($brand['email']))<div>{{ $brand['email'] }}</div>@endif
                @if (filled($brand['website']))<div>{{ $brand['website'] }}</div>@endif
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td>
                <div class="doc-title">@yield('title')</div>
                @hasSection('reference')
                    <div class="doc-reference">@yield('reference')</div>
                @endif
            </td>
            <td style="text-align: right; font-size: 9pt; color: #475569;">
                @yield('meta')
            </td>
        </tr>
    </table>

    @yield('content')

    <table class="footer">
        <tr>
            <td>
                {{ $brand['name'] }}
                {{-- From the same brand array as the header, so the footer
                     cannot show a stale registration after a settings change. --}}
                @if (filled($brand['registration_number'] ?? null))
                    · Reg. {{ $brand['registration_number'] }}
                @endif
                @if (filled($brand['tax_identification_number'] ?? null))
                    · TIN {{ $brand['tax_identification_number'] }}
                @endif
            </td>
            <td class="right">
                Generated {{ now()->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))->format('j M Y, H:i') }}
                ({{ config('pisfa.business_timezone', 'Africa/Kampala') }})
            </td>
        </tr>
    </table>
</body>
</html>
