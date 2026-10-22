@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <div class="registrar-case-closed">
        <section aria-labelledby="closed-heading">
            <p class="registrar-case-meta">{{ $outcome }}</p>
            <h2 id="closed-heading">{{ ! $knownOutcome ? 'The enrollment outcome needs review' : ($case->canonical_outcome === \App\Models\Enrollment::OutcomeNotEnrolled ? 'The enrollment cutoff passed' : 'Registration was cancelled') }}</h2>
            @if ($case->status_reason)<p>{{ $case->status_reason }}</p>@endif
            @if ($closingEvent)
                <p class="registrar-case-meta">Recorded by {{ $closingEvent->actor?->getFilamentName() ?? 'System' }}@if ($closingEvent->recorded_at) · {{ \App\Support\DisplayDateTime::format($closingEvent->recorded_at) }}@endif</p>
            @endif
            @if ($knownOutcome)
                <p>Any reserved seats were released. Payments and earlier registration history remain recorded.</p>
            @else
                <p>Review the recorded history before deciding the next step. Reopening is unavailable for this outcome.</p>
            @endif
        </section>
        @if ($knownOutcome)
            <section class="registrar-case-reopen" aria-labelledby="reopen-heading">
                <h2 id="reopen-heading">Resume this enrollment</h2>
                @if ($cutoff !== null)
                    <p class="registrar-case-meta">Published enrollment cutoff: {{ \App\Support\DisplayDateTime::format($cutoff) }}</p>
                @endif
                @if ($cutoff === null)
                    <p>The final enrollment cutoff is unavailable. Registrar must check the Term calendar before reopening.</p>
                @elseif ($lateAuthorityRequired)
                    <p>The final cutoff has passed. Reopening requires an approved authority for this learner, case and Term.</p>
                    @if (! $hasLateAuthority)
                        {{ $this->getAction('recordLateReopenAuthority', isMounting: false) }}
                    @else
                        {{ $this->getAction('reopenCase', isMounting: false) }}
                    @endif
                @else
                    <p>Reopen this same case to prepare current subjects and check eligibility again.</p>
                    {{ $this->getAction('reopenCase', isMounting: false) }}
                @endif
            </section>
        @endif
    </div>
    <a class="registrar-case-history-link" href="{{ $this->enrollmentUrl(['view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
</div>
