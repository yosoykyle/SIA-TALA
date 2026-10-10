<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Acknowledgment — {{ $source['application_reference'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/tala-foundation.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tala-application-acknowledgment.css') }}">
    <script src="{{ asset('js/tala-application-acknowledgment.js') }}" defer></script>
    <script src="{{ asset('js/tala-reference.js') }}" defer></script>
</head>
<body>
    @php
        $queue = request()->query('queue');
        $recordParameters = ['record' => $application];
        if (in_array($queue, ['needs_review', 'waiting_for_applicant', 'official_credentials', 'ready_for_enrollment', 'history'], true)) {
            $recordParameters['queue'] = $queue;
        }
    @endphp
    <nav class="controls" aria-label="Acknowledgment actions">
        <a href="{{ $actor->id === $application->user_id
            ? route('filament.applicant.pages.dashboard', ['application' => $application->id])
            : \App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource::getUrl('view', $recordParameters) }}">
            <x-heroicon-o-arrow-left aria-hidden="true" /> Back to {{ $application->user_id === $actor->id ? 'Applicant Home' : 'Applicant Record' }}
        </a>
        <button type="button" data-print-acknowledgment><x-heroicon-o-printer aria-hidden="true" /> Print or save</button>
    </nav>

    <main>
        <table class="document" role="presentation">
        <thead><tr><td>
        <header class="document-header">
            <div class="issuer"><img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" width="56" height="56"><strong>{{ config('institution.public.name', 'Servitech Institute Asia') }}</strong></div>
            <h1>Application acknowledgment</h1>
            <p class="application-reference">Reference <x-application-reference :value="$source['application_reference']" /></p>
            <p class="applicant-identity">{{ collect([data_get($version->snapshot, 'first_name'), data_get($version->snapshot, 'middle_name'), data_get($version->snapshot, 'last_name'), data_get($version->snapshot, 'extension_name')])->filter()->implode(' ') }} · Submitted version {{ $version->version }} · Requirement Set version {{ $version->requirementSet->version }}</p>
            <p class="version-status {{ $source['is_current'] ? 'is-current' : 'is-historical' }}">{{ $source['is_current'] ? 'Current submitted version' : 'Historical superseded version' }}</p>
        </header>
        </td></tr></thead>
        <tbody>
        <tr><td>

        <section aria-labelledby="application-heading">
            <h2 id="application-heading">Application received</h2>
            <dl>
                <dt>Submitted</dt><dd>{{ $version->submitted_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</dd>
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
                <dt>Student type</dt><dd>{{ \App\Models\AdmissionCycle::studentTypeLabel($source['application_path']) }}</dd>
            </dl>
        </section>
        </td></tr>

        <tr><td>
        <section aria-labelledby="details-heading">
            <h2 id="details-heading">Submitted contact and education details</h2>
            <dl>
                <dt>Birth date</dt><dd>{{ data_get($version->snapshot, 'birth_date', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Citizenship</dt><dd>{{ data_get($version->snapshot, 'citizenship_country_code', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Verified account email</dt><dd>{{ data_get($version->snapshot, 'email', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Mobile</dt><dd>{{ data_get($version->snapshot, 'phone', 'Unavailable in this historical snapshot') }}</dd>
                <dt>City or municipality</dt><dd>{{ data_get($version->snapshot, 'current_city_municipality', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Province</dt><dd>{{ data_get($version->snapshot, 'current_province', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Prior school</dt><dd>{{ data_get($version->snapshot, 'prior_school_name', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Educational attainment</dt><dd>{{ str(data_get($version->snapshot, 'credential_basis', 'Unavailable in this historical snapshot'))->lower()->headline() }}</dd>
                <dt>Graduation year</dt><dd>{{ data_get($version->snapshot, 'prior_school_completion_year', 'Unavailable in this historical snapshot') }}</dd>
                <dt>LRN availability</dt><dd>{{ str(data_get($version->snapshot, 'lrn_availability', 'Unavailable in this historical snapshot'))->headline() }}</dd>
                @if (filled(data_get($version->snapshot, 'lrn')))
                    <dt>LRN</dt><dd>{{ data_get($version->snapshot, 'lrn') }}</dd>
                @endif
                @if (filled(data_get($version->snapshot, 'guardian_full_name')))
                    <dt>Parent, guardian or emergency contact</dt><dd>{{ data_get($version->snapshot, 'guardian_full_name') }} — {{ data_get($version->snapshot, 'guardian_relationship') }} — {{ data_get($version->snapshot, 'guardian_mobile') }}</dd>
                @endif
            </dl>
        </section>
        </td></tr>

        <tr><td>
            <h2>Evidence at submission</h2>
        </td></tr>
            @foreach (data_get($version->snapshot, 'requirements', []) as $requirement)
                <tr><td>
                <section class="receipt-evidence">
                    <h3>{{ $requirement['label'] }}</h3>
                    <p>{{ $requirement['applicant_instructions'] ?: 'No instruction recorded' }}</p>
                    <dl>
                        <dt>Due stage / method</dt><dd>{{ str($requirement['due_stage'])->headline() }} · {{ str($requirement['official_submission_method'])->headline() }}</dd>
                        <dt>Preliminary copy</dt><dd>{{ str($requirement['preliminary_result'])->headline() }}{{ isset($requirement['evidence']['id']) ? ' · Evidence '.$requirement['evidence']['id'] : '' }}</dd>
                        <dt>Retained credential result</dt><dd>{{ filled($requirement['official_credential_result'] ?? null) ? str($requirement['official_credential_result'])->headline() : 'No individual result recorded' }}</dd>
                    </dl>
                </section>
                </td></tr>
            @endforeach
            @if (! is_array(data_get($version->snapshot, 'requirements')))
                <tr><td>
                <p>Requirement instructions, evidence, and review states as of submission are unavailable in this historical snapshot.</p>
                </td></tr>
            @endif

        <tr><td>
        <section aria-labelledby="source-heading">
            <h2 id="source-heading">Submission and output references</h2>
            <dl>
                <dt>Application version</dt><dd>{{ $version->version }}</dd>
                <dt>Requirement Set version</dt><dd>{{ $version->requirementSet->version }}</dd>
                <dt>Version status</dt><dd>{{ $source['is_current'] ? 'Current' : 'Historical and superseded' }}</dd>
                <dt>Submitted</dt><dd>{{ $version->submitted_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</dd>
                <dt>Application state at submission</dt><dd>{{ data_get($version->snapshot, 'application_state_at_submission', 'Unavailable in this historical snapshot') }}</dd>
                <dt>Output reference</dt><dd>{{ $outputReference }}</dd>
                <dt>Generated</dt><dd>{{ $generatedAt->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</dd>
            </dl>
        </section>

        <p class="notice">
            This acknowledgment confirms receipt of this application version. Admission and official enrollment are recorded separately.
        </p>

        <footer>
            Generated through TALA · Immutable source: Application {{ $source['application_reference'] }}, version {{ $version->version }}, Requirement Set version {{ $version->requirementSet->version }}.
        </footer>
        </td></tr>
        </tbody>
        </table>
    </main>
</body>
</html>
