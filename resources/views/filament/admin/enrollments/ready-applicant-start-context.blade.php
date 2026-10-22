@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets

@if ($application)
    <section class="registrar-ready-start" aria-label="Selected applicant">
        <h3>{{ collect([$application->first_name, $application->middle_name, $application->last_name])->filter()->implode(' ') }}</h3>
        <p>{{ $application->application_reference }}</p>
        <dl>
            <div><dt>Program</dt><dd>{{ $application->program?->name ?? 'Program not assigned' }}</dd></div>
            <div><dt>Enrollment term</dt><dd>{{ $application->term?->label ?? 'Term not assigned' }}</dd></div>
        </dl>
    </section>
    <p class="registrar-ready-start-help">You’ll review proposed subjects next. This does not make enrollment official.</p>
@endif
