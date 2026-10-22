@props([
    'title',
    'context' => null,
    'generatedAt' => null,
    'subtitle' => null,
    'pageOrientation' => 'portrait',
    'pageMargin' => '12mm',
    'logoSrc' => null,
    'backUrl' => null,
    'backLabel' => 'Back',
    'schedulePresentation' => false,
    'documentClass' => null,
])

@php
    $resolvedLogo = $logoSrc ?: asset('images/brand/servitech-crest.webp');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('institution.name') }}</title>
    @if ($schedulePresentation)<link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">@endif
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css').'?v='.filemtime(public_path('css/tala-foundation.css')) }}">
    <style>
        :root {
            color: #111827;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }

        body {
            background: #f3f4f6;
            margin: 0;
        }

        .official-output-toolbar,
        .official-output {
            margin-left: auto;
            margin-right: auto;
            max-width: 1100px;
        }

        .official-output-toolbar {
            padding: 24px 24px 0;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .official-output-toolbar :is(a, button) {
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 16px;
            border: 1px solid var(--tala-control-border);
            border-radius: 8px;
            background-color: var(--tala-surface);
            background-image: var(--tala-control-gloss);
            box-shadow: var(--tala-control-depth);
            color: var(--tala-text);
            font: 600 14px/1.4 Arial, sans-serif;
            text-decoration: none;
            cursor: pointer;
        }
        .official-output-toolbar button {
            background-color: var(--tala-brand-strong);
            border-color: var(--tala-brand-strong);
            color: var(--tala-on-brand);
        }
        .official-output-toolbar :is(a, button):focus-visible {
            outline: 2px solid var(--tala-focus);
            outline-offset: 3px;
        }
        .official-output-toolbar :is(a, button):active { box-shadow: var(--tala-control-pressed); }

        .official-output {
            background: #ffffff;
            border: 1px solid #d1d5db;
            margin-bottom: 24px;
            margin-top: 16px;
            padding: 32px;
        }

        .official-output-header {
            align-items: start;
            border-bottom: 2px solid #111827;
            display: flex;
            gap: 24px;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 16px;
        }

        .official-output-identity {
            align-items: center;
            display: flex;
            gap: 14px;
        }

        .official-output-logo {
            height: 58px;
            object-fit: contain;
            width: 58px;
        }

        .official-output-header h1,
        .official-output-header p {
            margin: 0;
        }

        .official-output-header h1 {
            font-size: 22px;
            margin-top: 6px;
        }

        .official-output-meta {
            color: #4b5563;
            line-height: 1.5;
            margin-top: 6px;
        }

        .official-output-context {
            border: 1px solid #111827;
            font-weight: 700;
            padding: 8px 12px;
            text-align: center;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
        }

        .official-output-notice {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            margin-top: 24px;
            padding: 12px;
        }

        .official-output-footer {
            color: #4b5563;
            font-size: 11px;
            margin-top: 24px;
        }

        .finance-grid {
            display: grid;
            gap: 8px 24px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-bottom: 24px;
        }

        .finance-heading {
            font-size: 16px;
            margin: 24px 0 8px;
        }

        .finance-summary {
            margin-top: 16px;
        }

        .schedule-owner {
            margin-bottom: 16px;
        }

        .schedule-empty {
            border: 1px solid #d1d5db;
            padding: 16px;
        }

        .schedule-table {
            font-size: 12px;
        }

        @media (max-width: 720px) {
            .official-output {
                border-left: 0;
                border-right: 0;
                padding: 20px;
            }

            .official-output-header {
                display: block;
            }

            .official-output-context {
                display: inline-block;
                margin-top: 12px;
            }

            .official-output-table {
                overflow-x: auto;
            }

            .finance-responsive-table thead {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .finance-responsive-table table,
            .finance-responsive-table tbody,
            .finance-responsive-table tr,
            .finance-responsive-table td {
                display: block;
                width: 100%;
                box-sizing: border-box;
            }

            .finance-responsive-table tr {
                border: 1px solid #d1d5db;
                margin-bottom: 12px;
            }

            .finance-responsive-table td {
                display: grid;
                grid-template-columns: minmax(96px, 40%) minmax(0, 1fr);
                gap: 12px;
                border: 0;
                border-bottom: 1px solid #e5e7eb;
            }

            .finance-responsive-table td:last-child {
                border-bottom: 0;
            }

            .finance-responsive-table td::before {
                content: attr(data-label);
                font-weight: 700;
            }

            .finance-grid {
                grid-template-columns: 1fr;
            }
        }

        @if ($schedulePresentation)
        :root { font-family: 'Inter Variable', Arial, sans-serif; font-size:14px; }
        .official-output { padding:24px; }
        .official-output-header { margin-bottom:16px; }
        .schedule-owner { margin-block:12px; line-height:1.5; }
        .schedule-table { font-size:13px; font-variant-numeric:tabular-nums; }
        .schedule-table td,.schedule-table th { padding:10px 8px; vertical-align:top; }
        .schedule-table th { text-align:start; }
        .schedule-table tbody tr:nth-child(even) { background:#f6f8f6; }
        .schedule-meeting-time { white-space:nowrap; font-weight:600; }
        .schedule-course-title { font-weight:600; }
        .schedule-source-code { color:#4b5563; font-size:.9em; }
        @media print { .schedule-table { font-size:10px; } .schedule-table td,.schedule-table th { padding:6px; } .official-output { padding:0; } }
        @endif

        @media print {
            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .official-output-toolbar {
                display: none !important;
            }

            .official-output {
                border: 0 !important;
                margin: 0 !important;
                max-width: none !important;
                padding: 0 !important;
            }

            .official-output-notice {
                background: #ffffff !important;
                border: 1px solid #000000 !important;
                color: #000000 !important;
            }

            .official-output-context {
                border: 2px solid #000000 !important;
                color: #000000 !important;
                background: #ffffff !important;
            }

            th {
                background: #f3f4f6 !important;
                color: #000000 !important;
                border: 1px solid #374151 !important;
            }

            td {
                border: 1px solid #4b5563 !important;
                color: #000000 !important;
            }

            table {
                break-inside: auto;
                page-break-inside: auto;
            }

            tr,
            .official-output-context,
            .official-output-identity,
            .official-output-notice {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            h1,
            h2,
            h3,
            .finance-heading {
                break-after: avoid;
                page-break-after: avoid;
            }

            @page {
                size: A4 {{ $pageOrientation }};
                margin: {{ $pageMargin }};

                @bottom-center {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 10px;
                }

                @bottom-left {
                    content: "{{ strtoupper((string) $context) }}";
                    font-size: 10px;
                    font-weight: 700;
                }
            }
        }

    </style>
    {{ $documentStyles ?? '' }}
</head>
<body class="{{ $documentClass }}">
    <div class="official-output-toolbar">
        @if ($backUrl)<a href="{{ $backUrl }}">{{ $backLabel }}</a>@endif
        <button type="button" onclick="window.print()">Print or save PDF</button>
    </div>

    @if (request()->boolean('print'))
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

    <main class="official-output">
        @isset($documentHeader)
            {{ $documentHeader }}
        @else
        <header class="official-output-header">
            <div class="official-output-identity">
                @if (filled($resolvedLogo))
                    <img class="official-output-logo" src="{{ $resolvedLogo }}" alt="{{ config('institution.name') }} logo">
                @endif
                <div>
                <p><strong>{{ config('institution.name') }}</strong></p>
                @if (filled(config('institution.address')))
                    <p class="official-output-meta">{{ config('institution.address') }}</p>
                @endif
                <h1>{{ $title }}</h1>
                @if (filled($subtitle))
                    <p class="official-output-meta">{{ $subtitle }}</p>
                @endif
                @if (filled($generatedAt))
                    <p class="official-output-meta">Generated {{ $generatedAt }}</p>
                @endif
                </div>
            </div>

            @if (filled($context))
                <div class="official-output-context">{{ $context }}</div>
            @endif
        </header>
        @endisset

        {{ $slot }}

        @isset($documentFooter)
            {{ $documentFooter }}
        @else
        <footer class="official-output-footer">
            Generated through TALA from authenticated records. Confirm any discrepancy with the responsible school office.
        </footer>
        @endisset
    </main>
</body>
</html>
