<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificate of Registration</title>
    <style>
        :root {
            color: #111827;
            font-family: Arial, sans-serif;
            font-size: 13px;
        }

        body {
            margin: 0;
            background: #f3f4f6;
        }

        .page {
            max-width: 960px;
            margin: 24px auto;
            padding: 32px;
            background: #ffffff;
            border: 1px solid #d1d5db;
        }

        .header,
        .summary-grid,
        .signature-grid {
            display: grid;
            gap: 12px;
        }

        .header {
            grid-template-columns: 96px 1fr 180px;
            align-items: center;
            border-bottom: 2px solid #111827;
            padding-bottom: 16px;
        }

        .logo {
            height: 72px;
            border: 1px solid #9ca3af;
            display: grid;
            place-items: center;
            font-weight: 700;
        }

        h1,
        h2,
        p {
            margin: 0;
        }

        h1 {
            font-size: 20px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        h2 {
            margin-top: 22px;
            margin-bottom: 10px;
            font-size: 14px;
            text-transform: uppercase;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 4px;
        }

        .copy-box {
            border: 1px solid #111827;
            padding: 10px;
            text-align: center;
            font-weight: 700;
        }

        .summary-grid {
            grid-template-columns: repeat(4, 1fr);
        }

        .field {
            border: 1px solid #d1d5db;
            padding: 8px;
            min-height: 42px;
        }

        .label {
            display: block;
            color: #4b5563;
            font-size: 10px;
            text-transform: uppercase;
        }

        .value {
            display: block;
            margin-top: 3px;
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #9ca3af;
            padding: 6px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
        }

        .right {
            text-align: right;
        }

        .signature-grid {
            grid-template-columns: repeat(4, 1fr);
            margin-top: 36px;
        }

        .signature {
            border-top: 1px solid #111827;
            padding-top: 6px;
            text-align: center;
            min-height: 48px;
        }

        .toolbar {
            max-width: 960px;
            margin: 24px auto 0;
            text-align: right;
        }

        .toolbar button {
            border: 0;
            border-radius: 6px;
            background: #1d4ed8;
            color: #ffffff;
            cursor: pointer;
            font-weight: 700;
            padding: 10px 14px;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .toolbar {
                display: none;
            }

            .page {
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
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <main class="page">
        <header class="header">
            <div class="logo">SIA</div>
            <div>
                <p><strong>SERVITECH INSTITUTE ASIA INC.</strong></p>
                <h1>Registration Form / Certificate of Registration</h1>
                <p>Generated {{ $cor['generated_at']->format('M d, Y h:i A') }}</p>
            </div>
            <div class="copy-box">{{ str($cor['copy_context'])->replace('_', ' ')->headline() }}</div>
        </header>

        <h2>Student Information</h2>
        <section class="summary-grid">
            @foreach ([
                'Student No.' => $cor['summary']['student_number'],
                'LRN / Prior ID' => $cor['summary']['prior_identifier'] ?? '-',
                'Full Name' => $cor['summary']['student_name'],
                'Program' => $cor['summary']['program'],
                'Year Level' => $cor['summary']['year_level'],
                'Term' => $cor['summary']['term'],
                'Registration Date' => $cor['summary']['registration_date'],
                'Delivery Modality' => $cor['summary']['delivery_modality'],
                'Payment Status' => $cor['summary']['payment_status'],
                'Total Units' => $cor['summary']['total_units'],
                'Balance' => 'PHP '.$cor['summary']['balance'],
                'Schedule Version' => $cor['schedule_version'] ?? '-',
            ] as $label => $value)
                <div class="field">
                    <span class="label">{{ $label }}</span>
                    <span class="value">{{ $value }}</span>
                </div>
            @endforeach
        </section>

        <h2>Class Schedule / Subjects</h2>
        <table>
            <thead>
                <tr>
                    <th>Subject Code</th>
                    <th>Description</th>
                    <th>Units</th>
                    <th>Lec Hrs</th>
                    <th>Lab Hrs</th>
                    <th>Section</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Room</th>
                    <th>Instructor</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cor['subjects'] as $subject)
                    <tr>
                        <td>{{ $subject['subject_code'] }}</td>
                        <td>{{ $subject['subject_description'] }}</td>
                        <td class="right">{{ $subject['units'] }}</td>
                        <td class="right">{{ $subject['lecture_hours'] }}</td>
                        <td class="right">{{ $subject['laboratory_hours'] }}</td>
                        <td>{{ $subject['section'] }}</td>
                        <td>{{ $subject['day'] }}</td>
                        <td>{{ $subject['time'] }}</td>
                        <td>{{ $subject['room'] }}</td>
                        <td>{{ $subject['instructor'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">No enrolled subjects are available for this COR.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <h2>Computation of Fees</h2>
        <table>
            <tbody>
                @foreach ($cor['fees'] as $fee)
                    <tr>
                        <th>{{ $fee['label'] }}</th>
                        <td class="right">PHP {{ $fee['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <section class="signature-grid">
            <div class="signature">Encoded / Enlisted By</div>
            <div class="signature">Evaluated By / Registrar</div>
            <div class="signature">Assessed By / Accounting</div>
            <div class="signature">Approved By / School Administrator</div>
        </section>
    </main>
</body>
</html>
