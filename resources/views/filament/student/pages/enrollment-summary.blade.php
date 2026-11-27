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
        $case?->currentProposalVersion?->confirmation !== null && ($requirement['state'] ?? '') === 'Cleared' => 'Your enrollment needs Registrar attention',
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
        'identityStudies' => $studyContext['is_official']
            ? $studyContext['study_level_label'].' · '.$studyContext['semester'].' · '.$studyContext['academic_year']
            : ($studyContext['study_level_label'] !== 'Unavailable' ? $studyContext['study_level_label'].' (provisional)' : null),
    ])
    @php
        $financialHolds = $activeFinancialHolds ?? collect();
        $hasFinancialHolds = $financialHolds->isNotEmpty();
        $isAwaitingEnrollment = $case === null;
        $hasPriorCredits = ($priorCreditsSummary['count'] ?? 0) > 0;
        $priorPassedCount = (int) ($priorCreditsSummary['passed_count'] ?? 0);
        $priorUnitsFormatted = rtrim(rtrim(number_format((float) ($priorCreditsSummary['units'] ?? 0), 2, '.', ''), '0'), '.');
        $showNotice = $notice && (! $proofPending || $notice['tier'] !== 'Payment Pending or Rejected');
        $financialHoldCoversNotice = $hasFinancialHolds && ($notice['tier'] ?? null) === 'Enrollment Blocked';
        $showSecondaryNotice = $showNotice && ! ($isAwaitingEnrollment && $financialHoldCoversNotice);
        $welcomeSpansWide = $hasFinancialHolds || $showSecondaryNotice;
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
        $remainingHolds = $hasFinancialHolds && ($isAwaitingEnrollment || ($position['state'] ?? 'Unavailable') === 'Unavailable')
            ? $activeHolds->reject(fn (\App\Models\Hold $hold): bool => $hold->hold_type === \App\Models\Hold::TypeFinancial)->values()
            : $activeHolds;
    @endphp
    <div class="student-bento">
        @if ($isAwaitingEnrollment)
            <section @class(['student-tile student-overview-welcome student-tile-tint', 'student-tile-wide' => $welcomeSpansWide, 'student-tile-full' => ! $welcomeSpansWide]) aria-labelledby="overview-readiness-heading">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-academic-cap" />Enrollment readiness</p>
                <h2 id="overview-readiness-heading">{{ $hasPriorCredits ? 'Welcome back, '.($student?->first_name ?: 'Student') : 'Welcome to Servitech Student Hub' }}</h2>
                @if (($context['can_start'] ?? false) && ! $hasFinancialHolds)
                    <p>Enrollment is open for {{ $context['term']?->label }}. Review your study plan or go to Enrollment when you are ready to register.</p>
                @elseif ($hasFinancialHolds)
                    <p>Your student record is active. Coordinate with Accounting to clear your prior-balance hold before next-term enrollment.</p>
                @else
                    <p>Your student record is active. No enrollment period is open right now — review your study plan and recorded credits while waiting for next term.</p>
                @endif
                <dl class="student-welcome-facts" aria-label="Student standing summary">
                    <div>
                        <dt>Program</dt>
                        <dd>{{ $studyContext['program_name'] ?? 'Being recorded' }}</dd>
                    </div>
                    <div>
                        <dt>Year standing</dt>
                        <dd>{{ $studyContext['study_level_label'] !== 'Unavailable' ? $studyContext['study_level_label'].' (provisional)' : 'Pending enrollment' }}</dd>
                    </div>
                    <div>
                        <dt>Prior credits</dt>
                        <dd>
                            @if ($hasPriorCredits)
                                {{ $priorPassedCount }} {{ $priorPassedCount === 1 ? 'subject' : 'subjects' }} ({{ $priorUnitsFormatted }} {{ (float) ($priorCreditsSummary['units'] ?? 0) === 1.0 ? 'unit' : 'units' }})
                            @else
                                None recorded yet
                            @endif
                        </dd>
                    </div>
                </dl>
                <div class="student-tile-actions">
                    @if (($context['can_start'] ?? false) && ! $hasFinancialHolds)
                        <x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Go to Enrollment</x-filament::button>
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">Review your study plan</x-filament::button>
                    @else
                        <x-filament::button tag="a" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Review your study plan</x-filament::button>
                        @if ($hasPriorCredits)
                            <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'record'], panel: 'student')">View academic record</x-filament::button>
                        @else
                            <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">Go to Enrollment</x-filament::button>
                        @endif
                    @endif
                </div>
            </section>
            @if ($hasFinancialHolds)
                @php
                    $holdMessages = $financialHolds->map(fn (\App\Models\Hold $hold): string => $hold->studentFacingMessage())->filter()->unique()->values();
                    $holdNotes = $financialHolds
                        ->filter(fn (\App\Models\Hold $hold): bool => filled($hold->reason) && $hold->reason !== $hold->studentFacingMessage() && $hold->reason !== $hold->staff_only_reason)
                        ->pluck('reason')
                        ->unique()
                        ->values();
                @endphp
                <section class="student-tile student-tile-wide student-tile-attention" role="status" aria-labelledby="overview-financial-hold-heading">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-exclamation-triangle" />Prior-balance hold</p>
                    <h2 id="overview-financial-hold-heading">Financial hold on your account</h2>
                    @foreach ($holdMessages as $msg)
                        <p>{{ $msg }}</p>
                    @endforeach
                    @foreach ($holdNotes as $note)
                        <p class="student-tile-meta"><strong>Hold note:</strong> {{ $note }}</p>
                    @endforeach
                    <p class="student-tile-meta">Once settled, the Accounting Office or Registrar will lift this hold so you can enroll and request official records.</p>
                    <div class="student-tile-actions">
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button>
                    </div>
                </section>
            @endif
        @else
            <section @class(['student-tile student-overview-payment', 'student-tile-wide' => $showSecondaryNotice, 'student-tile-full' => ! $showSecondaryNotice, 'student-tile-fill' => $paymentDue, 'student-tile-attention' => ! $proofPending && ($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds, 'student-tile-info' => $proofPending || (($position['state'] ?? 'Unavailable') === 'Unavailable' && ! $hasFinancialHolds)]) aria-labelledby="overview-payment-heading">
                <p class="student-tile-kicker"><x-filament::icon :icon="! $proofPending && ($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-credit-card'" />{{ ! $proofPending && ($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds ? 'Prior-balance hold' : 'Fees and payments' }}</p>
                @if ($proofPending)<h2 id="overview-payment-heading">Your submitted receipt is under review</h2><p>Accounting is reviewing your PHP {{ number_format((float) $latestEvidence->claimed_amount, 2) }} receipt. No action is needed while it is reviewed.</p>
                @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds)
                    <h2 id="overview-payment-heading">Financial hold on your account</h2>
                    @foreach ($financialHolds as $hold)
                        <p>{{ $hold->studentFacingMessage() }}</p>
                        @if (filled($hold->reason) && $hold->reason !== $hold->studentFacingMessage() && $hold->reason !== $hold->staff_only_reason)
                            <p class="student-tile-meta"><strong>Hold note:</strong> {{ $hold->reason }}</p>
                        @endif
                    @endforeach
                    <p class="student-tile-meta">Once settled, the Accounting Office or Registrar will lift this hold.</p>
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
        @endif
        @if ($showSecondaryNotice)
            <section class="student-tile student-tile-wide student-tile-attention" aria-label="Needs your attention">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-information-circle" />Needs your attention</p>
                @if ($irregular = $notice['irregular'] ?? null)
                    <h2>{{ $irregular['confirmed'] ? 'Status: '.$irregular['status_label'] : 'Flagged for irregular review' }}</h2>
                    <p class="student-tile-meta">{{ $notice['scope_label'] }}</p>
                    <x-irregular-reasons :review="$irregular" audience="student" class="student-tile-irregular" />
                    @if ($notice['required_action'])<p>{{ $notice['required_action'] }}</p>@endif
                    <ol class="student-tile-steps" aria-label="What happens next">
                        <li @if (! $irregular['confirmed']) aria-current="step" @endif>Registrar confirms</li>
                        <li @if ($irregular['confirmed']) aria-current="step" @endif>Apply as irregular</li>
                        <li>Registrar approval</li>
                        <li>Assessment and payment</li>
                        <li>Enrolled</li>
                    </ol>
                @else
                <h2>{{ $notice['tier'] }}</h2>
                @if ($notice['tier'] === 'Academic Guidance')<p class="student-tile-meta">{{ $notice['scope_label'] }}</p>@endif
                <p>{{ $notice['student_reason'] }}</p>
                @if ($notice['required_action'])<p>{{ $notice['required_action'] }}</p>@endif
                @endif
                @if ($notice['tier'] === 'Academic Guidance')
                    @if ($notice['academic_effect'] === 'ADVISING_REQUIRED' && ! ($notice['irregular'] ?? null))
                        <ol class="student-tile-steps" aria-label="What happens next"><li aria-current="step">You ask the Registrar</li><li>Registrar prepares your subjects</li><li>You review them</li></ol>
                    @endif
                    <div class="student-tile-actions">
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Enrollment::getUrl(array_filter(['term' => $notice['term_id']]), panel: 'student')">Review enrollment</x-filament::button>
                        <x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'record'], panel: 'student')">View your results</x-filament::button>
                    </div>
                @elseif ($notice['office_to_contact'])<p class="student-tile-meta">Contact {{ $notice['office_to_contact'] }}.</p>@endif
            </section>
        @endif
        <section @class(['student-tile student-overview-enrollment', 'student-tile-wide' => $showResults !== $showPlan, 'student-tile-full' => ! $showResults && ! $showPlan, 'student-tile-tint' => $official]) aria-labelledby="overview-enrollment-heading">
            <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-document-text" />Enrollment</p>
            <h2 id="overview-enrollment-heading">{{ $title }}</h2>
            <p class="student-tile-meta">{{ $case?->term?->label ?? $context['term']?->label ?? 'The Registrar will confirm the enrollment period.' }}@if ($showResults) · {{ $academicSnapshot['subjects'] }} {{ $academicSnapshot['subjects'] === 1 ? 'subject' : 'subjects' }}@endif</p>
            @if ($official || ! $showSecondaryNotice || ($context['reason'] ?? null) !== $notice['student_reason'])<p>{{ $official ? 'Open your enrollment record to check subjects and document availability.' : ($context['reason'] ?? 'Review your subjects and fees when the Registrar has prepared them.') }}</p>@endif
            <div class="student-tile-actions"><x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View enrollment</x-filament::button></div>
        </section>
        @if ($showResults)
            <section @class(['student-tile', 'student-tile-wide' => ! $showPlan]) aria-labelledby="overview-results-heading">
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
            <section @class(['student-tile', 'student-tile-wide' => ! $showResults]) aria-labelledby="overview-plan-heading">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-map" />Study plan</p>
                <h2 id="overview-plan-heading">{{ (float) $academicSnapshot['completed_units'] }} of {{ (float) $academicSnapshot['total_units'] }} units completed</h2>
                <div class="student-tile-bar" role="img" aria-label="{{ $planPercent }} percent of curriculum units completed"><span style="inline-size: {{ $planPercent }}%"></span></div>
                <p class="student-tile-meta">{{ implode(' · ', $planParts) }}</p>
                <div class="student-tile-actions"><x-filament::button tag="a" color="gray" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">View study plan</x-filament::button></div>
            </section>
        @endif
        @if ($remainingHolds->isNotEmpty())
            <section class="student-tile student-tile-full student-tile-attention" aria-label="School matters to resolve">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-exclamation-triangle" />Holds</p>
                <h2>School matters to resolve</h2>
                <ul class="student-tile-list">@foreach ($remainingHolds as $hold)<li>{{ $hold->studentFacingMessage() }}</li>@endforeach</ul>
            </section>
        @endif
        @php
            $hasWeeklySchedule = $schedulePresentation && ($schedulePresentation['meetings'] ?? []) !== [];
            $hasExternalClasses = ($externallyArrangedClasses ?? []) !== [];
        @endphp
        <section class="student-tile student-tile-full student-home-schedule" aria-labelledby="home-schedule-heading">
            <div class="student-home-schedule-heading">
                <h2 class="student-tile-kicker" id="home-schedule-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Class schedule</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::link :href="\App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Open full schedule</x-filament::link>
                    @if (($activeOfficialTermCount ?? 0) >= 2)
                        <x-filament::link :href="\App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')" icon="heroicon-m-arrows-right-left">Switch active term</x-filament::link>
                    @endif
                    @if ($official && ($hasWeeklySchedule || $hasExternalClasses))
                        <x-filament::link :href="route('student.schedule.print')" target="_blank" icon="heroicon-m-printer">Print schedule</x-filament::link>
                    @endif
                </div>
            </div>
            @if (($recentScheduleRevisions ?? []) !== [])
                <section class="tala-warning-banner mb-3" role="status" aria-labelledby="home-schedule-changes-heading">
                    <div class="space-y-1">
                        <h3 id="home-schedule-changes-heading" class="flex items-center gap-2 text-sm font-semibold">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                            <span>Affected by recent change</span>
                        </h3>
                        <p class="text-sm">
                            {{ count($recentScheduleRevisions) === 1 ? '1 of your classes has a recent schedule update.' : count($recentScheduleRevisions).' of your classes have recent schedule updates.' }}
                        </p>
                    </div>
                    <ul class="tala-faculty-affected-list mt-1">
                        @foreach ($recentScheduleRevisions as $revision)
                            <li class="tala-faculty-affected-row">
                                <div class="tala-faculty-affected-row__main">
                                    <x-filament::badge :color="$revision['badge_color']" size="sm">
                                        {{ $revision['badge_label'] }}
                                    </x-filament::badge>
                                    <strong>{{ $revision['course_section'] }}</strong>
                                    <span class="tabular-nums">{{ $revision['schedule_line'] }}</span>
                                </div>
                                <span class="tala-faculty-affected-row__detail">{{ $revision['detail_line'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            @if ($hasWeeklySchedule)
                <x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'home-'.$case->current_cor_version_id" />
            @endif
            @if ($hasExternalClasses)
                <div class="mt-3 space-y-2">
                    <h3 class="text-sm font-semibold">Externally arranged classes</h3>
                    <ul class="student-tile-list">
                        @foreach ($externallyArrangedClasses as $externalClass)
                            <li><strong>{{ $externalClass['course_code'] }} · {{ $externalClass['course_title'] }}</strong> ({{ $externalClass['section_code'] }}) — Externally arranged · No fixed weekly room or time slot</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (! $hasWeeklySchedule && ! $hasExternalClasses)
                <p class="student-home-empty">{{ $official ? 'No weekly meetings are recorded. Ask the Registrar about your class schedule.' : 'Your class schedule appears here after official enrollment.' }}</p>
            @endif
        </section>
    </div>
</div>
