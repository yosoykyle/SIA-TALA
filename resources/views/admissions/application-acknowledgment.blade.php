<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Acknowledgment — {{ $source['application_reference'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/tala-application-acknowledgment.css') }}">
    <script src="{{ asset('js/tala-application-acknowledgment.js') }}" defer></script>
</head>
<body>
    <nav class="controls" aria-label="Acknowledgment actions">
        <a href="{{ $actor->id === $application->user_id
            ? route('filament.applicant.pages.dashboard')
            : \App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource::getUrl('view', ['record' => $application]) }}">
            Back to {{ $application->user_id === $actor->id ? 'Applicant Home' : 'Applicant Record' }}
        </a>
        <button type="button" data-print-acknowledgment>Print or save</button>
    </nav>

    <main>
        <header class="document-header">
            <strong>{{ config('institution.public.name', 'Servitech Institute Asia') }}</strong>
            <h1>APPLICATION ACKNOWLEDGMENT</h1>
            <p>Application {{ $source['application_reference'] }}</p>
            <p class="version-status">{{ $source['is_current'] ? 'Current submitted version' : 'Historical superseded version' }}</p>
        </header>

        <section aria-labelledby="source-heading">
            <h2 id="source-heading">Bound source</h2>
            <dl>
                <dt>Application version</dt><dd>{{ $version->version }}</dd>
                <dt>Requirement Set version</dt><dd>{{ $version->requirementSet->version }}</dd>
                <dt>Version status</dt><dd>{{ $source['is_current'] ? 'Current' : 'Historical and superseded' }}</dd>
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
                <dt>Admission Cycle</dt><dd>{{ $source['admission_cycle'] }}{{ filled($source['admission_cycle_code']) ? ' ('.$source['admission_cycle_code'].')' : '' }}</dd>
                <dt>Target term</dt><dd>{{ $source['term'] }}</dd>
                <dt>Program</dt><dd>{{ $source['program'] }}</dd>
                <dt>Path</dt><dd>{{ str($source['application_path'])->headline() }}</dd>
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
            Generated through TALA · Immutable source: Application {{ $source['application_reference'] }}, version {{ $version->version }}, Requirement Set version {{ $version->requirementSet->version }}.
        </footer>
    </main>
</body>
</html>
