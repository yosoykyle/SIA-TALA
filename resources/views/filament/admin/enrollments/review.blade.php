@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php($kind = $review['kind'])
<div class="registrar-case">
    <div class="registrar-case-identity"><p>{{ $review['case']->case_reference }}</p><p>{{ $review['proposal']?->curriculumVersion?->program?->name }}</p><p>{{ $review['case']->term?->label }}</p></div>
    <section class="registrar-case-preparation">
        <div><h2>{{ match ($kind) { 'overload' => 'Record the approved overload', 'timetable' => 'Review the proposed schedule change', default => 'Review the academic source change' } }}</h2>
        <p>{{ $kind === 'overload' ? 'This Draft exceeds its exact curriculum-term load. Record the existing graduating approval before reserving or sending these subjects.' : 'Review what the changed source means for this registration before sending or confirming the current proposal.' }}</p></div>
        <div class="registrar-case-task-actions">{{ $this->getAction($kind === 'overload' ? 'recordGraduatingOverloadAuthority' : 'resolveImpactReview', isMounting: false) }}</div>
    </section>
    <div class="registrar-case-grid">
        <section><h2>{{ $kind === 'overload' ? 'Exact proposal load' : 'Change to review' }}</h2>
            @include('filament.admin.enrollments.review-source-context')
            @if (count($review['options']) > 1)<p>{{ count($review['options']) }} open source changes. Choose the exact change in the review form.</p>@endif
            <details class="registrar-case-reference"><summary>{{ $review['proposal']?->state === \App\Models\RegistrationProposalVersion::StateDraft ? 'Proposed subjects and schedule' : 'Current proposal subjects and schedule' }}</summary>
                @foreach ($review['proposal']?->items ?? [] as $item)
                    <p><strong>{{ $item->course_title_snapshot }}</strong> &middot; {{ $item->section?->code }} &middot; {{ (float) $item->units_snapshot }} units</p>
                    @foreach ($item->meeting_snapshot ?? [] as $meeting)<p>{{ ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][$meeting['day_of_week'] ?? 0] }} &middot; {{ substr($meeting['starts_at'] ?? '',0,5) }}-{{ substr($meeting['ends_at'] ?? '',0,5) }}</p>@endforeach
                @endforeach
            </details>
        </section>
        <section><h2>After this review</h2><p>{{ $kind === 'overload' ? 'This approval covers only this proposed load. Seats, current records, student confirmation and payment requirements must still be checked.' : 'Recording an outcome closes this review only. It changes no academic result, registration or published timetable. Remaining source and enrollment checks still apply.' }}</p>
            @if ($review['case']->canonical_outcome === \App\Models\Enrollment::OutcomeInProgress){{ $this->getAction('prepareProposal', isMounting: false) }}@endif
        </section>
    </div>
    <a class="registrar-case-history-link" href="{{ $this->enrollmentUrl(['view' => 'history']) }}"><x-filament::icon icon="heroicon-o-clock" /><span><strong>Enrollment history</strong><span>Activity and supporting records</span></span><x-filament::icon icon="heroicon-m-chevron-right" /></a>
</div>
