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
    @if ($notice && (! $proofPending || $notice['tier'] !== 'Payment Pending or Rejected'))
        <section class="student-home-notice" aria-label="Needs your attention"><h2>{{ $notice['tier'] }}</h2><p>{{ $notice['student_reason'] }}</p>@if ($notice['required_action'])<p>{{ $notice['required_action'] }}</p>@endif @if ($notice['office_to_contact'])<p>Contact {{ $notice['office_to_contact'] }}.</p>@endif</section>
    @endif
    <div class="student-overview-board">
        <section class="student-overview-enrollment"><h2><x-filament::icon icon="heroicon-o-document-text" class="student-overview-task-icon" /><span>{{ $title }}</span></h2><p class="student-profile-meta">{{ $case?->term?->label ?? $context['term']?->label ?? 'The Registrar will confirm the enrollment period.' }}</p><p>{{ $official ? 'Your registered subjects and certificate are ready.' : ($context['reason'] ?? 'Review your subjects and fees when the Registrar has prepared them.') }}</p><x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View enrollment</x-filament::button></section>
        <section class="student-overview-payment">
            @if ($proofPending)<h2><x-filament::icon icon="heroicon-o-credit-card" class="student-overview-task-icon" /><span>Your payment proof is under review</span></h2><p>Accounting is reviewing your PHP {{ number_format((float) $latestEvidence->claimed_amount, 2) }} receipt. No action is needed while it is reviewed.</p>
            @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable')<h2><x-filament::icon icon="heroicon-o-credit-card" class="student-overview-task-icon" /><span>Accounting is updating your fees</span></h2><p>Your balance will appear when your assessment is ready.</p>
            @elseif ($position['next_obligation'] ?? null)<h2><x-filament::icon icon="heroicon-o-credit-card" class="student-overview-task-icon" /><span>{{ (float) $position['current_due'] > 0 ? 'Your next payment is due' : 'Your next scheduled payment' }}</span></h2><p class="student-home-amount">PHP {{ number_format((float) $position['next_obligation']['balance'], 2) }}</p><p class="student-profile-meta">{{ $position['next_obligation']['due_at'] ? 'Due '.\Carbon\CarbonImmutable::parse($position['next_obligation']['due_at'])->timezone(config('app.display_timezone'))->format('M j, Y') : 'Ask Accounting for the due date' }}</p>
            @else<h2><x-filament::icon icon="heroicon-o-credit-card" class="student-overview-task-icon" /><span>No remaining balance</span></h2><p>Your current term account has no outstanding payment.</p>@endif
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button>
        </section>
    </div>
    @if ($activeHolds->isNotEmpty())<section class="student-home-notice" aria-label="School matters to resolve"><h2>School matters to resolve</h2><ul>@foreach ($activeHolds as $hold)<li>{{ $hold->studentFacingMessage() }}</li>@endforeach</ul></section>@endif
    <div class="student-overview-records">
    <section class="student-home-schedule" aria-labelledby="home-schedule-heading"><div class="student-home-schedule-heading"><h2 id="home-schedule-heading"><x-filament::icon icon="heroicon-o-calendar-days" class="student-overview-task-icon" /><span>Class schedule</span></h2>@if ($official)<x-filament::link :href="route('student.schedule.print')" target="_blank" icon="heroicon-m-printer">Print schedule</x-filament::link>@endif</div>@if ($schedulePresentation && $schedulePresentation['meetings'] !== [])<x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'home-'.$case->current_cor_version_id" />@else<p class="student-home-empty">{{ $official ? 'No weekly meetings are recorded. Ask the Registrar about your class schedule.' : 'Your class schedule appears here after official enrollment.' }}</p>@endif</section>
    <nav class="student-overview-links" aria-label="Student records"><x-filament::link :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">View study plan</x-filament::link><x-filament::link :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'record'], panel: 'student')">View academic record</x-filament::link><x-filament::link :href="\App\Filament\Student\Pages\Profile::getUrl(panel: 'student')">View profile</x-filament::link></nav>
    </div>
</div>
