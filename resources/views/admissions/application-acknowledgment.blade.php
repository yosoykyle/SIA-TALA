<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Acknowledgment — {{ $application->application_reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #111; }
        body { margin: 0; background: #eceff3; line-height: 1.45; }
        main { box-sizing: border-box; width: min(210mm, 100%); min-height: 297mm; margin: 24px auto; padding: 14mm; background: #fff; }
        header { border-bottom: 3px solid #111; padding-bottom: 12px; }
        h1 { margin: 8px 0 0; font-size: 22px; letter-spacing: .06em; }
        h2 { margin: 22px 0 8px; font-size: 16px; border-bottom: 1px solid #555; padding-bottom: 4px; }
        p { margin: 5px 0; }
        dl { display: grid; grid-template-columns: minmax(130px, 1fr) 2fr; gap: 5px 14px; margin: 10px 0; }
        dt { font-weight: 700; }
        dd { margin: 0; overflow-wrap: anywhere; }
        table { border-collapse: collapse; width: 100%; font-size: 12px; }
        th, td { border: 1px solid #555; padding: 7px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .notice { margin-top: 18px; border: 2px solid #111; padding: 10px; font-weight: 700; }
        .controls { width: min(210mm, 100%); margin: 14px auto; display: flex; gap: 8px; }
        .controls a, .controls button { border: 1px solid #111; background: #fff; color: #111; padding: 9px 14px; font: inherit; cursor: pointer; text-decoration: none; }
        footer { margin-top: 24px; border-top: 1px solid #555; padding-top: 8px; font-size: 11px; }
        @media (max-width: 640px) {
            main { margin: 0; padding: 18px; min-height: 100vh; }
            .controls { margin: 0; padding: 10px; box-sizing: border-box; }
            dl { grid-template-columns: 1fr; }
            dt { margin-top: 6px; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
            tr { border: 1px solid #555; margin-bottom: 8px; }
            td { border: 0; border-bottom: 1px solid #ddd; }
            td::before { content: attr(data-label) ': '; font-weight: 700; }
        }
        @media print {
            body { background: #fff; }
            main { margin: 0; padding: 0; width: auto; min-height: auto; }
            .controls { display: none !important; }
            thead { display: table-header-group; }
            header { break-after: avoid; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <nav class="controls" aria-label="Acknowledgment actions">
        <a href="{{ $actor->id === $application->user_id
            ? route('filament.applicant.pages.dashboard')
            : \App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource::getUrl('view', ['record' => $application]) }}">
            Back to {{ $application->user_id === $actor->id ? 'Applicant Home' : 'Applicant Record' }}
        </a>
        <button type="button" onclick="window.print()">Print or save</button>
    </nav>

    <main>
        <header>
            <strong>{{ config('institution.public.name', 'Servitech Institute Asia') }}</strong>
            <h1>APPLICATION ACKNOWLEDGMENT</h1>
            <p>Application {{ $application->application_reference }}</p>
        </header>

        <section aria-labelledby="source-heading">
            <h2 id="source-heading">Bound source</h2>
            <dl>
                <dt>Application version</dt><dd>{{ $version->version }}</dd>
                <dt>Requirement Set version</dt><dd>{{ $version->requirementSet->version }}</dd>
                <dt>Submitted</dt><dd>{{ $version->submitted_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</dd>
                <dt>Output reference</dt><dd>{{ $outputReference }}</dd>
                <dt>Generated</dt><dd>{{ $generatedAt->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</dd>
            </dl>
        </section>

        <section aria-labelledby="application-heading">
            <h2 id="application-heading">Submitted application summary</h2>
            <dl>
                <dt>Applicant</dt>
                <dd>{{ collect([
                    data_get($version->snapshot, 'first_name'),
                    data_get($version->snapshot, 'middle_name'),
                    data_get($version->snapshot, 'last_name'),
                    data_get($version->snapshot, 'extension_name'),
                ])->filter()->implode(' ') }}</dd>
                <dt>Admission Cycle</dt><dd>{{ $application->admissionCycle?->label }} ({{ $application->admissionCycle?->code }})</dd>
                <dt>Target term</dt><dd>{{ $application->term?->label }}</dd>
                <dt>Program</dt><dd>{{ $application->program?->name }}</dd>
                <dt>Path</dt><dd>{{ str($application->application_path)->headline() }}</dd>
                <dt>Prior school</dt><dd>{{ data_get($version->snapshot, 'prior_school_name', 'Not provided') }}</dd>
            </dl>
        </section>

        <section aria-labelledby="requirements-heading">
            <h2 id="requirements-heading">Requirements governing this submission</h2>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Requirement</th>
                        <th scope="col">Due stage</th>
                        <th scope="col">Official method</th>
                        <th scope="col">Instruction at submission</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($version->requirementSet->requirements->sortBy('display_order') as $requirement)
                        <tr>
                            <td data-label="Requirement">{{ $requirement->label }}</td>
                            <td data-label="Due stage">{{ str($requirement->due_stage)->headline() }}</td>
                            <td data-label="Official method">{{ str($requirement->official_submission_method)->headline() }}</td>
                            <td data-label="Instruction">{{ $requirement->applicant_instructions }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <p class="notice">
            This acknowledgment confirms receipt of the identified application version only. It is not an admission certificate, proof of enrollment, Certificate of Registration, or Student record.
        </p>

        <footer>
            Generated through TALA · Historical source: Application {{ $application->application_reference }}, version {{ $version->version }}, Requirement Set version {{ $version->requirementSet->version }}.
        </footer>
    </main>
</body>
</html>
