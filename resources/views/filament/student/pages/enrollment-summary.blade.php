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
    $financialHolds = $activeFinancialHolds ?? collect();
    $hasFinancialHolds = $financialHolds->isNotEmpty();
    $isAwaitingEnrollment = $case === null;
    $hasPriorCredits = ($priorCreditsSummary['count'] ?? 0) > 0;
    $priorPassedCount = (int) ($priorCreditsSummary['passed_count'] ?? 0);
    $formatUnits = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    $priorUnitsFormatted = $formatUnits((float) ($priorCreditsSummary['units'] ?? 0));
    $showNotice = $notice && (! $proofPending || $notice['tier'] !== 'Payment Pending or Rejected');
    $financialHoldCoversNotice = $hasFinancialHolds && ($notice['tier'] ?? null) === 'Enrollment Blocked';
    $showSecondaryNotice = $showNotice && ! ($isAwaitingEnrollment && $financialHoldCoversNotice);
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
    $publishedAnnouncements = $announcements ?? collect();

    $enrollmentKpiValue = match (true) {
        $official => 'Enrolled',
        $case !== null && in_array($case->canonical_outcome, \App\Models\Enrollment::reopenableOutcomes(), true) => 'Closed',
        $case !== null && $case->canonical_outcome !== \App\Models\Enrollment::OutcomeInProgress => 'Needs review',
        $case?->currentProposalVersion?->state === \App\Models\RegistrationProposalVersion::StateIssued => 'Ready to review',
        $case !== null => 'In progress',
        ($context['can_start'] ?? false) && ! $hasFinancialHolds => 'Open',
        $hasFinancialHolds => 'On hold',
        default => 'Pending',
    };
    $enrollmentKpiTone = match ($enrollmentKpiValue) {
        'Enrolled', 'Open' => 'success',
        'On hold' => 'danger',
        'Needs review', 'Ready to review', 'In progress' => 'warning',
        default => 'default',
    };
    $enrollmentKpiMeta = $case?->term?->label ?? $context['term']?->label ?? 'No open enrollment period';

    $registeredUnits = (float) ($academicSnapshot['registered_units'] ?? 0);
    $cumulativeGwa = $academicSnapshot['cumulative_gwa'] ?? null;
    if ($showResults) {
        $academicKpiLabel = 'Current load';
        $academicKpiValue = $registeredUnits > 0
            ? $formatUnits($registeredUnits).' '.($registeredUnits === 1.0 ? 'unit' : 'units')
            : $academicSnapshot['subjects'].' '.($academicSnapshot['subjects'] === 1 ? 'subject' : 'subjects');
        $academicKpiMeta = collect([
            $academicSnapshot['subjects'].' '.($academicSnapshot['subjects'] === 1 ? 'subject' : 'subjects'),
            filled($cumulativeGwa) ? 'GWA '.$cumulativeGwa : $academicSnapshot['released'].' released',
        ])->implode(' · ');
        $academicKpiTone = ($academicSnapshot['incomplete'] ?? 0) > 0 ? 'warning' : 'default';
    } elseif (filled($cumulativeGwa)) {
        $academicKpiLabel = 'Cumulative GWA';
        $academicKpiValue = $cumulativeGwa;
        $academicKpiMeta = filled($academicSnapshot['cumulative_gwa_through'] ?? null)
            ? 'Through '.$academicSnapshot['cumulative_gwa_through']
            : ($studyContext['study_level_label'] !== 'Unavailable' ? $studyContext['study_level_label'] : 'Released grades');
        $academicKpiTone = 'success';
    } else {
        $academicKpiLabel = 'Standing';
        $academicKpiValue = $studyContext['study_level_label'] !== 'Unavailable' ? $studyContext['study_level_label'] : 'Pending';
        $academicKpiMeta = $hasPriorCredits
            ? $priorPassedCount.' prior '.($priorPassedCount === 1 ? 'subject' : 'subjects').' ('.$priorUnitsFormatted.' '.((float) ($priorCreditsSummary['units'] ?? 0) === 1.0 ? 'unit' : 'units').')'
            : ($studyContext['is_official'] ? 'Official standing' : 'Pending enrollment');
        $academicKpiTone = 'default';
    }

    if ($hasFinancialHolds && ($isAwaitingEnrollment || ($position['state'] ?? 'Unavailable') === 'Unavailable')) {
        $balanceKpiValue = 'Hold active';
        $balanceKpiMeta = 'Coordinate with Accounting';
        $balanceKpiTone = 'danger';
    } elseif ($isAwaitingEnrollment) {
        $balanceKpiValue = 'None yet';
        $balanceKpiMeta = 'No active term assessment';
        $balanceKpiTone = 'default';
    } elseif ($proofPending) {
        $balanceKpiValue = 'Under review';
        $balanceKpiMeta = 'Receipt submitted for verification';
        $balanceKpiTone = 'warning';
    } elseif (($position['state'] ?? 'Unavailable') === 'Unavailable') {
        $balanceKpiValue = 'Pending';
        $balanceKpiMeta = 'Assessment in progress';
        $balanceKpiTone = 'default';
    } elseif ($position['next_obligation'] ?? null) {
        $balanceAmount = (float) ($position['remaining_balance'] ?? $position['next_obligation']['balance'] ?? 0);
        $balanceKpiValue = 'PHP '.number_format($balanceAmount, 2);
        $balanceKpiMeta = filled($position['next_obligation']['due_at'] ?? null)
            ? 'Due '.\Carbon\CarbonImmutable::parse($position['next_obligation']['due_at'])->timezone(config('app.display_timezone'))->format('M j, Y')
            : 'Ask Accounting for the due date';
        $balanceKpiTone = $paymentDue ? 'danger' : 'warning';
    } else {
        $balanceKpiValue = 'Settled';
        $balanceKpiMeta = 'No remaining balance';
        $balanceKpiTone = 'success';
    }

    $planKpiValue = $showPlan
        ? $formatUnits((float) $academicSnapshot['completed_units']).' / '.$formatUnits((float) $academicSnapshot['total_units']).' units'
        : 'Not assigned';
    $planKpiMeta = $showPlan ? $planPercent.'% completed' : 'View curriculum progress';
    $planKpiTone = match (true) {
        $showPlan && $planPercent >= 100 => 'success',
        ($academicSnapshot['plan']['attention'] ?? 0) > 0 => 'warning',
        default => 'default',
    };

    $overviewKpis = [
        [
            'label' => 'Enrollment',
            'value' => $enrollmentKpiValue,
            'meta' => $enrollmentKpiMeta,
            'icon' => 'heroicon-o-clipboard-document-check',
            'href' => \App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student'),
            'tone' => $enrollmentKpiTone,
        ],
        [
            'label' => $academicKpiLabel,
            'value' => $academicKpiValue,
            'meta' => $academicKpiMeta,
            'icon' => 'heroicon-o-academic-cap',
            'href' => \App\Filament\Student\Pages\Academics::getUrl(panel: 'student'),
            'tone' => $academicKpiTone,
        ],
        [
            'label' => 'Balance due',
            'value' => $balanceKpiValue,
            'meta' => $balanceKpiMeta,
            'icon' => 'heroicon-o-credit-card',
            'href' => \App\Filament\Student\Pages\Finance::getUrl(panel: 'student'),
            'tone' => $balanceKpiTone,
        ],
        [
            'label' => 'Study plan',
            'value' => $planKpiValue,
            'meta' => $planKpiMeta,
            'icon' => 'heroicon-o-map',
            'href' => \App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student'),
            'tone' => $planKpiTone,
        ],
    ];
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

    <x-tala-kpi-strip :items="$overviewKpis" aria-label="Student summary" />

    <div class="student-bento">
        @if ($showSecondaryNotice)
            @php
                $irregular = $notice['irregular'] ?? null;
                $irregularReasons = $irregular['reasons'] ?? [];
                $irregularPrimaryCode = $irregularReasons[0]['code'] ?? null;
                $noticeBadgeColor = ($irregular && $irregularPrimaryCode === 'failed') || in_array($notice['tier'] ?? null, ['Enrollment Blocked', 'COR Blocked', 'Security / Account Notice'], true)
                    ? 'danger'
                    : 'warning';
                $noticeHasActions = $notice['tier'] === 'Academic Guidance';
            @endphp
            <section class="student-tile student-tile-full tala-task-surface student-card--two-tone" aria-label="Needs your attention">
                <div class="student-card__body">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="student-tile-kicker">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                            <span>Needs your attention</span>
                        </p>
                        <x-filament::badge :color="$noticeBadgeColor" size="sm">
                            {{ $irregular ? ($irregular['confirmed'] ? 'Confirmed irregular' : 'Pending Registrar review') : $notice['tier'] }}
                        </x-filament::badge>
                    </div>

                    @if ($irregular)
                        <h2>{{ $irregular['confirmed'] ? 'Status: '.$irregular['status_label'] : 'Flagged for irregular review' }}</h2>
                        @if (filled($notice['scope_label'] ?? null))
                            <p class="student-tile-meta">{{ $notice['scope_label'] }}</p>
                        @endif
                        @if ($irregularReasons !== [])
                            <ol class="m-0 list-none divide-y divide-[var(--tala-edge)] border-t border-[var(--tala-edge)] p-0">
                                @foreach ($irregularReasons as $index => $reason)
                                    @php
                                        $isPrimary = $index === 0;
                                        $code = $reason['code'] ?? 'other';
                                        $tierBadgeColor = $isPrimary
                                            ? ($code === 'failed' ? 'danger' : 'warning')
                                            : 'gray';
                                    @endphp
                                    <li class="grid gap-1 py-2.5 first:pt-2.5 last:pb-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-filament::badge :color="$tierBadgeColor" size="sm">
                                                {{ $isPrimary ? 'Primary reason' : 'Secondary reason' }}
                                            </x-filament::badge>
                                            <strong class="text-sm font-semibold">{{ $reason['label'] }}</strong>
                                        </div>
                                        <p class="student-tile-meta">{{ $reason['details'] }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @if ($notice['required_action'])
                            <p class="border-t border-[var(--tala-edge)] pt-2.5">{{ $notice['required_action'] }}</p>
                        @endif
                    @else
                        <h2>{{ $notice['tier'] }}</h2>
                        @if ($notice['tier'] === 'Academic Guidance' && filled($notice['scope_label'] ?? null))
                            <p class="student-tile-meta">{{ $notice['scope_label'] }}</p>
                        @endif
                        <p>{{ $notice['student_reason'] }}</p>
                        @if ($notice['required_action'])
                            <p class="student-tile-meta">{{ $notice['required_action'] }}</p>
                        @endif
                    @endif

                    @if (! $noticeHasActions && $notice['office_to_contact'])
                        <p class="student-tile-meta border-t border-[var(--tala-edge)] pt-2.5">Contact {{ $notice['office_to_contact'] }}.</p>
                    @endif
                </div>

                @if ($noticeHasActions)
                    <div class="student-card__footer">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::button tag="a" color="primary" :href="\App\Filament\Student\Pages\Enrollment::getUrl(array_filter(['term' => $notice['term_id']]), panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Review enrollment</x-filament::button>
                            <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View grades</x-filament::button>
                        </div>
                    </div>
                @endif
            </section>
        @endif

        @if ($isAwaitingEnrollment && $hasFinancialHolds)
            @php
                $holdMessages = $financialHolds->map(fn (\App\Models\Hold $hold): string => $hold->studentFacingMessage())->filter()->unique()->values();
                $holdNotes = $financialHolds
                    ->filter(fn (\App\Models\Hold $hold): bool => filled($hold->reason) && $hold->reason !== $hold->studentFacingMessage() && $hold->reason !== $hold->staff_only_reason)
                    ->pluck('reason')
                    ->unique()
                    ->values();
            @endphp
            <section class="student-tile student-tile-full tala-task-surface student-card--two-tone" role="status" aria-labelledby="overview-financial-hold-heading">
                <div class="student-card__body">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-exclamation-triangle" />Prior-balance hold</p>
                        <x-filament::badge color="danger" size="sm">Action required</x-filament::badge>
                    </div>
                    <h2 id="overview-financial-hold-heading">Financial hold on your account</h2>
                    @foreach ($holdMessages as $msg)
                        <p>{{ $msg }}</p>
                    @endforeach
                    @foreach ($holdNotes as $note)
                        <p class="student-tile-meta"><strong>Hold note:</strong> {{ $note }}</p>
                    @endforeach
                    <p class="student-tile-meta">Once settled, the Accounting Office or Registrar will lift this hold so you can enroll and request official records.</p>
                </div>
                <div class="student-card__footer">
                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button>
                </div>
            </section>
        @endif

        <div class="student-overview-priority-grid student-tile-full">
            @if ($isAwaitingEnrollment)
                <section class="student-tile student-overview-welcome tala-task-surface student-card--two-tone" style="grid-column: auto;" aria-labelledby="overview-readiness-heading">
                    <div class="student-card__body">
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
                    </div>
                    <div class="student-card__footer">
                        <div class="flex flex-wrap items-center gap-2">
                            @if (($context['can_start'] ?? false) && ! $hasFinancialHolds)
                                <x-filament::button tag="a" color="primary" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Go to Enrollment</x-filament::button>
                                <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">Review your study plan</x-filament::button>
                                @if ($hasPriorCredits)
                                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View grades</x-filament::button>
                                @endif
                            @else
                                <x-filament::button tag="a" color="primary" :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">Review your study plan</x-filament::button>
                                @if ($hasPriorCredits)
                                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View grades</x-filament::button>
                                @else
                                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">Go to Enrollment</x-filament::button>
                                @endif
                            @endif
                        </div>
                    </div>
                </section>
            @else
                <section class="student-tile student-overview-payment tala-task-surface student-card--two-tone" style="grid-column: auto;" aria-labelledby="overview-payment-heading">
                    <div class="student-card__body">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="student-tile-kicker">
                                <x-filament::icon :icon="! $proofPending && ($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-credit-card'" />
                                <span>{{ ! $proofPending && ($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds ? 'Prior-balance hold' : 'Fees and payments' }}</span>
                            </p>
                            @if ($proofPending)
                                <x-filament::badge color="warning" size="sm">Under review</x-filament::badge>
                            @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds)
                                <x-filament::badge color="danger" size="sm">Hold active</x-filament::badge>
                            @elseif ($paymentDue)
                                <x-filament::badge color="danger" size="sm">Payment due</x-filament::badge>
                            @elseif (($position['state'] ?? 'Unavailable') !== 'Unavailable' && ! ($position['next_obligation'] ?? null))
                                <x-filament::badge color="success" size="sm">Settled</x-filament::badge>
                            @endif
                        </div>

                        @if ($proofPending)
                            <h2 id="overview-payment-heading">Your submitted receipt is under review</h2>
                            <p>Accounting is reviewing your PHP {{ number_format((float) $latestEvidence->claimed_amount, 2) }} receipt. No action is needed while it is reviewed.</p>
                        @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable' && $hasFinancialHolds)
                            <h2 id="overview-payment-heading">Financial hold on your account</h2>
                            @foreach ($financialHolds as $hold)
                                <p>{{ $hold->studentFacingMessage() }}</p>
                                @if (filled($hold->reason) && $hold->reason !== $hold->studentFacingMessage() && $hold->reason !== $hold->staff_only_reason)
                                    <p class="student-tile-meta"><strong>Hold note:</strong> {{ $hold->reason }}</p>
                                @endif
                            @endforeach
                            <p class="student-tile-meta">Once settled, the Accounting Office or Registrar will lift this hold.</p>
                        @elseif (($position['state'] ?? 'Unavailable') === 'Unavailable')
                            <h2 id="overview-payment-heading">Accounting is updating your fees</h2>
                            <p>Your balance will appear when your assessment is ready.</p>
                        @elseif ($position['next_obligation'] ?? null)
                            <h2 id="overview-payment-heading">{{ (float) $position['current_due'] > 0 ? 'Your next payment is due' : 'Your next scheduled payment' }}</h2>
                            <p class="student-tile-figure">PHP {{ number_format((float) $position['next_obligation']['balance'], 2) }}</p>
                            <p class="student-tile-meta">{{ $position['next_obligation']['due_at'] ? 'Due '.\Carbon\CarbonImmutable::parse($position['next_obligation']['due_at'])->timezone(config('app.display_timezone'))->format('M j, Y') : 'Ask Accounting for the due date' }}</p>
                        @else
                            <h2 id="overview-payment-heading">No remaining balance</h2>
                            <p>Your current term account has no outstanding payment.</p>
                        @endif

                        @if (! $proofPending && ($position['state'] ?? 'Unavailable') !== 'Unavailable' && ($position['obligations'] ?? []) !== [])
                            @php
                                $unpaidObligations = collect($position['obligations'])
                                    ->filter(fn (array $obligation): bool => (float) ($obligation['balance'] ?? 0) > 0 && ($obligation['balance'] ?? '0.00') !== '0.00')
                                    ->values()
                                    ->all();
                            @endphp
                            @if ($unpaidObligations !== [])
                                <ul class="student-tile-schedule" aria-label="Unpaid fees this term">
                                    @foreach (array_slice($unpaidObligations, 0, 4) as $obligation)
                                        @php
                                            $dueLabel = filled($obligation['due_at'] ?? null)
                                                ? 'Due '.\Carbon\CarbonImmutable::parse($obligation['due_at'])->timezone(config('app.display_timezone'))->format('M j, Y')
                                                : 'Ask Accounting for the due date';
                                        @endphp
                                        <li @class(['student-tile-schedule-due' => $obligation['is_due']])>
                                            <span class="student-tile-schedule-info">
                                                <span>{{ $obligation['label'] }}</span>
                                                <span class="student-tile-schedule-due-date">{{ $dueLabel }}</span>
                                            </span>
                                            <span class="student-tile-schedule-amount">PHP {{ number_format((float) $obligation['balance'], 2) }}</span>
                                            <x-filament::badge :color="$obligation['is_due'] ? 'danger' : 'gray'" size="sm">
                                                {{ $obligation['is_due'] ? 'Due' : 'Upcoming' }}
                                            </x-filament::badge>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="student-tile-meta">All current term fees are settled.</p>
                            @endif
                        @endif
                    </div>

                    <div class="student-card__footer">
                        <x-filament::button tag="a" :color="$paymentDue ? 'primary' : 'gray'" :outlined="! $paymentDue" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button>
                    </div>
                </section>
            @endif

            @if ($publishedAnnouncements->isNotEmpty())
                <section class="student-tile tala-task-surface student-card--two-tone" style="grid-column: auto;" aria-labelledby="overview-announcements-heading">
                    <div class="student-card__body">
                        <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-megaphone" />Announcements</p>
                        <h2 id="overview-announcements-heading">School updates</h2>
                        <ul class="m-0 list-none divide-y divide-[var(--tala-edge)] border-t border-[var(--tala-edge)] p-0">
                            @foreach ($publishedAnnouncements as $announcement)
                                <li class="flex flex-wrap items-start justify-between gap-3 py-2.5 first:pt-2.5 last:pb-0">
                                    <div class="grid min-w-0 gap-0.5">
                                        <strong class="text-sm font-semibold">{{ $announcement->title }}</strong>
                                        <p class="student-tile-meta">{{ $announcement->message }}</p>
                                    </div>
                                    @if (filled($announcement->link_url) && filled($announcement->link_label))
                                        <x-filament::button tag="a" size="sm" color="gray" outlined :href="$announcement->link_url">
                                            {{ $announcement->link_label }}
                                        </x-filament::button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </section>
            @endif
        </div>

        <section @class(['student-tile student-overview-enrollment tala-task-surface student-card--two-tone', 'student-tile-wide' => $showResults !== $showPlan, 'student-tile-full' => ! $showResults && ! $showPlan]) aria-labelledby="overview-enrollment-heading">
            <div class="student-card__body">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-document-text" />Enrollment</p>
                    @if ($official)
                        <x-filament::badge color="success" size="sm">Official</x-filament::badge>
                    @endif
                </div>
                <h2 id="overview-enrollment-heading">{{ $title }}</h2>
                <p class="student-tile-meta">{{ $case?->term?->label ?? $context['term']?->label ?? 'The Registrar will confirm the enrollment period.' }}@if ($showResults) · {{ $academicSnapshot['subjects'] }} {{ $academicSnapshot['subjects'] === 1 ? 'subject' : 'subjects' }}@endif</p>
                @if ($official || ! $showSecondaryNotice || ($context['reason'] ?? null) !== $notice['student_reason'])
                    <p>{{ $official ? 'Open your enrollment record to check subjects and document availability.' : ($context['reason'] ?? 'Review your subjects and fees when the Registrar has prepared them.') }}</p>
                @endif
            </div>
            <div class="student-card__footer">
                <x-filament::button tag="a" color="primary" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View enrollment</x-filament::button>
            </div>
        </section>

        @if ($showResults)
            <section @class(['student-tile tala-task-surface student-card--two-tone', 'student-tile-wide' => ! $showPlan]) aria-labelledby="overview-results-heading">
                <div class="student-card__body">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-academic-cap" />Results this term</p>
                    <div class="student-tile-split">
                        <div class="student-tile-ring" style="--tile-progress: {{ (int) round($academicSnapshot['released'] / $academicSnapshot['subjects'] * 100) }}" aria-hidden="true">{{ $academicSnapshot['released'] }}/{{ $academicSnapshot['subjects'] }}</div>
                        <div>
                            <h2 id="overview-results-heading">{{ $academicSnapshot['released'] }} of {{ $academicSnapshot['subjects'] }} released</h2>
                            <p class="student-tile-meta">{{ $academicSnapshot['incomplete'] > 0 ? $academicSnapshot['incomplete'].' incomplete (INC) to finish' : 'Results appear as your Faculty and the Registrar release them.' }}</p>
                        </div>
                    </div>
                </div>
                <div class="student-card__footer">
                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View grades</x-filament::button>
                </div>
            </section>
        @endif

        @if ($showPlan)
            <section @class(['student-tile tala-task-surface student-card--two-tone', 'student-tile-wide' => ! $showResults]) aria-labelledby="overview-plan-heading">
                <div class="student-card__body">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-map" />Study plan</p>
                    <h2 id="overview-plan-heading">{{ (float) $academicSnapshot['completed_units'] }} of {{ (float) $academicSnapshot['total_units'] }} units completed</h2>
                    <div class="student-tile-bar" role="img" aria-label="{{ $planPercent }} percent of curriculum units completed"><span style="inline-size: {{ $planPercent }}%"></span></div>
                    <p class="student-tile-meta">{{ implode(' · ', $planParts) }}</p>
                </div>
                <div class="student-card__footer">
                    <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'plan'], panel: 'student')">View study plan</x-filament::button>
                </div>
            </section>
        @endif

        @if ($remainingHolds->isNotEmpty())
            <section class="student-tile student-tile-full tala-task-surface" aria-label="School matters to resolve">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-exclamation-triangle" />Holds</p>
                    <x-filament::badge color="warning" size="sm">{{ $remainingHolds->count() }} {{ $remainingHolds->count() === 1 ? 'matter' : 'matters' }}</x-filament::badge>
                </div>
                <h2>School matters to resolve</h2>
                <ul class="m-0 list-none divide-y divide-[var(--tala-edge)] border-t border-[var(--tala-edge)] p-0">
                    @foreach ($remainingHolds as $hold)
                        <li class="py-2 text-sm first:pt-2.5 last:pb-0">{{ $hold->studentFacingMessage() }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
