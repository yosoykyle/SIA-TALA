@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $official = $case?->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
    $proofPending = $latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateSubmitted;
    $title = match (true) {
        $official => 'Your enrollment is official',
        $case !== null && in_array($case->canonical_outcome, \App\Models\Enrollment::reopenableOutcomes(), true) => 'This enrollment is closed',
        $case !== null && $case->canonical_outcome !== \App\Models\Enrollment::OutcomeInProgress => 'Ask the Registrar about this enrollment',
        $context['reason'] !== null => 'Your enrollment needs an office update',
        $case?->currentProposalVersion?->state === \App\Models\RegistrationProposalVersion::StateIssued => 'Your subjects are ready to review',
        $case?->currentProposalVersion?->confirmation !== null && ($requirement['state'] ?? '') === 'Cleared' => 'The Registrar can now finalize your enrollment',
        $case !== null => 'The Registrar is preparing your enrollment',
        default => 'Start your next enrollment',
    };
    $student = auth()->user()->studentProfile;
@endphp
<div class="learner-enrollment student-home student-review-home">
    @include('filament.components.student-identity', [
        'identityName' => trim($student?->first_name.' '.$student?->last_name),
        'identityProgram' => $studyContext['program_name'] ?? 'Your program is being recorded',
        'identityNumber' => $student?->student_number,
        'identityStudies' => $studyContext['is_official'] ? $studyContext['study_level_label'].' · '.$studyContext['semester'].' · '.$studyContext['academic_year'] : null,
    ])
    @php
        $showNotice = $notice && (! $proofPending || $notice['tier'] !== 'Payment Pending or Rejected');
        $paymentDue = ! $proofPending && ($position['state'] ?? 'Unavailable') !== 'Unavailable' && ($position['next_obligation'] ?? null) && (float) $position['current_due'] > 0;
        $showResults = $official && ($academicSnapshot['subjects'] ?? 0) > 0;
        $showPlan = ($academicSnapshot['total_units'] ?? 0) > 0;
        $planPercent = $showPlan ? (int) round($academicSnapshot['completed_units'] / $academicSnapshot['total_units'] * 100) : 0;
        $planParts = $showPlan ? array_filter([
            $academicSnapshot['plan']['done'] ? $academicSnapshot['plan']['done'].' completed' : null,
            $academicSnapshot['plan']['active'] ? $academicSnapshot['plan']['active'].' this term' : null,
            $academicSnapshot['plan']['attention'] ? $academicSnapshot['plan']['attention'].' to discuss with the Registrar' : null,
            $academicSnapshot['plan']['later'] ? $academicSnapshot['plan']['later'].' not yet enrolled' : null,
        ]) : [];
    @endphp
    <div class="student-bento">
        <section @class(['student-tile student-tile-wide student-overview-payment', 'student-tile-fill' => $paymentDue]) aria-labelledby="overview-payment-heading">
            <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-credit-card" />Fees and payments</p>
            @if ($proofPending)<h2 id="overview-payment-heading">Your payment proof is under review</h2><p>Accounting is reviewing your PHP {{ number_format((float) $latestEvidence->claimed_amount, 2) }} receipt. No action is needed while it is reviewed.</p>
            @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable')<h2 id="overview-payment-heading">Accounting is updating your fees</h2><p>Your balance will appear when your assessment is ready.</p>
            @elseif ($position['next_obligation'] ?? null)<h2 id="overview-payment-heading">{{ (float) $position['current_due'] > 0 ? 'Your next payment is due' : 'Your next scheduled payment' }}</h2><p class="student-tile-figure">PHP {{ number_format((float) $position['next_obligation']['balance'], 2) }}</p><p class="student-tile-meta">{{ $position['next_obligation']['due_at'] ? 'Due '.\Carbon\CarbonImmutable::parse($position['next_obligation']['due_at'])->timezone(config('app.display_timezone'))->format('M j, Y') : 'Ask Accounting for the due date' }}</p>
            @else<h2 id="overview-payment-heading">No remaining balance</h2><p>Your current term account has no outstanding payment.</p>@endif
            @if (! $proofPending && ($position['state'] ?? 'Unavailable') !== 'Unavailable' && ($position['obligations'] ?? []) !== [])
                <ul class="student-tile-schedule" aria-label="Payment schedule this term">
                    @foreach (array_slice($position['obligations'], 0, 4) as $obligation)
                        <li @class(['student-tile-schedule-done' => $obligation['balance'] === '0.00', 'student-tile-schedule-due' => $obligation['balance'] !== '0.00' && $obligation['is_due']])><span>{{ $obligation['label'] }}</span><span class="student-tile-schedule-amount">PHP {{ number_format((float) $obligation['amount'], 2) }}</span><span class="student-tile-chip">{{ $obligation['balance'] === '0.00' ? 'Satisfied' : ($obligation['is_due'] ? 'Due' : 'Upcoming') }}</span></li>
                    @endforeach
                </ul>
            @endif
            <div class="student-tile-actions"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button></div>
        </section>
        @if ($showNotice)
            <section class="student-tile student-tile-wide student-tile-attention" aria-label="Needs your attention">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-information-circle" />Needs your attention</p>
                <h2>{{ $notice['tier'] }}</h2>
                <p>{{ $notice['student_reason'] }}</p>
                @if ($notice['required_action'])<p>{{ $notice['required_action'] }}</p>@endif
                @if ($notice['tier'] === 'Academic Guidance')
                    <ol class="student-tile-steps" aria-label="What happens next"><li aria-current="step">You ask the Registrar</li><li>Registrar prepares your subjects</li><li>You review them</li></ol>
                    <div class="student-tile-actions"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View your results</x-filament::button></div>
                @elseif ($notice['office_to_contact'])<p class="student-tile-meta">Contact {{ $notice['office_to_contact'] }}.</p>@endif
            </section>
        @endif
        <section @class(['student-tile student-overview-enrollment', 'student-tile-wide' => ! $showNotice, 'student-tile-tint' => $official]) aria-labelledby="overview-enrollment-heading">
            <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-document-text" />Enrollment</p>
            <h2 id="overview-enrollment-heading">{{ $title }}</h2>
            <p class="student-tile-meta">{{ $case?->term?->label ?? $context['term']?->label ?? 'The Registrar will confirm the enrollment period.' }}@if ($showResults) · {{ $academicSnapshot['subjects'] }} {{ $academicSnapshot['subjects'] === 1 ? 'subject' : 'subjects' }}@endif</p>
            <p>{{ $official ? 'Open your enrollment record to check subjects and document availability.' : ($context['reason'] ?? 'Review your subjects and fees when the Registrar has prepared them.') }}</p>
            <div class="student-tile-actions"><x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View enrollment</x-filament::button></div>
        </section>
        @if ($showResults)
            <section class="student-tile" aria-labelledby="overview-results-heading">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-academic-cap" />Results this term</p>
                <div class="student-tile-split">
                    <div class="student-tile-ring" style="--tile-progress: {{ (int) round($academicSnapshot['released'] / $academicSnapshot['subjects'] * 100) }}" aria-hidden="true">{{ $academicSnapshot['released'] }}/{{ $academicSnapshot['subjects'] }}</div>
                    <div><h2 id="overview-results-heading">{{ $academicSnapshot['released'] }} of {{ $academicSnapshot['subjects'] }} released</h2>
                    <p class="student-tile-meta">{{ $academicSnapshot['incomplete'] > 0 ? $academicSnapshot['incomplete'].' incomplete (INC) to finish' : 'Results appear as your Faculty and the Registrar release them.' }}</p></div>
                </div>
                <div class="student-tile-actions"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View results</x-filament::button></div>
            </section>
        @endif
        @if ($showPlan)
            <section @class(['student-tile', 'student-tile-wide' => ! $showResults && $showNotice]) aria-labelledby="overview-plan-heading">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-map" />Study plan</p>
                <h2 id="overview-plan-heading">{{ (float) $academicSnapshot['completed_units'] }} of {{ (float) $academicSnapshot['total_units'] }} units completed</h2>
                <div class="student-tile-bar" role="img" aria-label="{{ $planPercent }} percent of curriculum units completed"><span style="inline-size: {{ $planPercent }}%"></span></div>
                <p class="student-tile-meta">{{ implode(' · ', $planParts) }}</p>
                <div class="student-tile-actions"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">View study plan</x-filament::button></div>
            </section>
        @endif
        @if ($activeHolds->isNotEmpty())
            <section class="student-tile student-tile-full student-tile-attention" aria-label="School matters to resolve">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-exclamation-triangle" />Holds</p>
                <h2>School matters to resolve</h2>
                <ul class="student-tile-list">@foreach ($activeHolds as $hold)<li>{{ $hold->studentFacingMessage() }}</li>@endforeach</ul>
            </section>
        @endif
        <section class="student-tile student-tile-full student-home-schedule" aria-labelledby="home-schedule-heading">
            <div class="student-home-schedule-heading"><h2 class="student-tile-kicker" id="home-schedule-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Class schedule</h2>@if ($official && $schedulePresentation && $schedulePresentation['meetings'] !== [])<x-filament::link :href="route('student.schedule.print')" target="_blank" icon="heroicon-m-printer">Print schedule</x-filament::link>@endif</div>
            @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])<x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'home-'.$case->current_cor_version_id" />@else<p class="student-home-empty">{{ $official ? 'No weekly meetings are recorded. Ask the Registrar about your class schedule.' : 'Your class schedule appears here after official enrollment.' }}</p>@endif
        </section>
    </div>
</div>
