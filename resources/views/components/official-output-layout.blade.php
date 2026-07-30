@props([
    'title',
    'context' => null,
    'generatedAt' => null,
    'subtitle' => null,
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | {{ config('institution.name') }}</title>
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
            text-align: right;
        }

        .official-output-toolbar button {
            background: #1d4ed8;
            border: 0;
            border-radius: 6px;
            color: #ffffff;
            cursor: pointer;
            font-weight: 700;
            padding: 10px 14px;
        }

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

        @media print {
            body {
                background: #ffffff;
            }

            .official-output-toolbar {
                display: none;
            }

            .official-output {
                border: 0;
                margin: 0;
                max-width: none;
                padding: 0;
            }

            @page {
                margin: 14mm;
            }
        }

    </style>
</head>
<body>
    <div class="official-output-toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    @if (request()->boolean('print'))
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

    <main class="official-output">
        <header class="official-output-header">
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

            @if (filled($context))
                <div class="official-output-context">{{ $context }}</div>
            @endif
        </header>

        {{ $slot }}

        <footer class="official-output-footer">
            Generated from authenticated TALA records. Confirm any discrepancy with the responsible school office.
        </footer>
    </main>
</body>
</html>
