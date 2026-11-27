@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $queueUrl = fn (string $key): string => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $key]);
    $featuredKey = $counts['prepare'] > 0 ? 'prepare' : ($counts['issues'] > 0 ? 'issues' : 'prepare');
    $continuation = $continuation ?? [
        'active_term' => null,
        'prior_term' => null,
        'enrolled_count' => 0,
        'did_not_proceed_count' => 0,
        'in_progress_count' => 0,
        'prior_cohort_count' => 0,
        'empty_note' => 'No prior term is available to compare yet.',
        'progressions' => [],
        'students' => [],
    ];
    $enrolledCount = (int) ($continuation['enrolled_count'] ?? 0);
    $didNotProceedCount = (int) ($continuation['did_not_proceed_count'] ?? 0);
    $inProgressCount = (int) ($continuation['in_progress_count'] ?? 0);
    $progressions = $continuation['progressions'] ?? [];
    $paymentQueueSummary = $paymentQueueSummary ?? [
        'total' => (int) ($counts['finance'] ?? 0),
        'for_verification' => 0,
        'pending_payment' => (int) ($counts['finance'] ?? 0),
    ];
    $paymentQueueTotal = (int) ($paymentQueueSummary['total'] ?? 0);
    $forVerificationCount = (int) ($paymentQueueSummary['for_verification'] ?? 0);
    $pendingPaymentCount = (int) ($paymentQueueSummary['pending_payment'] ?? 0);
    $paymentQueueBreakdown = $paymentQueueTotal > 0
        ? collect([
            $forVerificationCount > 0 ? $forVerificationCount.' for Accounting verification' : null,
            $pendingPaymentCount > 0 ? $pendingPaymentCount.' pending payment' : null,
        ])->filter()->implode(' · ')
        : 'No students currently awaiting payment or verification.';
@endphp
<div class="registrar-workbench tala-workspace-launcher">
    <section aria-labelledby="registrar-work-heading">
        <h2 id="registrar-work-heading">Registrar work</h2>
        <p class="tala-workspace-launcher__scope">Current enrollment cases across all terms</p>
        <nav class="tala-workspace-launcher__main" aria-label="Registrar enrollment work">
            @foreach ([
                ['ready_to_prepare', 'prepare', 'heroicon-o-document-text', 'New enrollments to prepare', 'Review these and send the student their class schedule.', 'Prepare subjects'],
                ['placement_shortages', 'issues', 'heroicon-o-exclamation-circle', 'Resolve enrollment issues', 'Review current classes or requirements that need attention.', 'Review issues'],
            ] as [$queue, $countKey, $icon, $label, $description, $action])
                <x-workspace-card :featured="$countKey === $featuredKey" :action-color="$countKey === $featuredKey ? 'primary' : 'gray'" :href="$queueUrl($queue)" :icon="$icon" :label="$label" :description="$description"
                    :figure="$counts[$countKey]" :figure-label="\Illuminate\Support\Str::plural('enrollment case', $counts[$countKey])" :action="$action" :heading-level="3" />
            @endforeach
            <section class="tala-task-surface tala-workspace-feature tala-workspace-feature--neutral registrar-continuation-card" aria-labelledby="registrar-continuation-heading">
                <div class="tala-workspace-feature__body">
                    <span class="tala-workspace-card__icon" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-arrow-trending-up" />
                    </span>
                    <h3 id="registrar-continuation-heading">Enrollment continuation</h3>
                    <x-workspace-figure :value="$enrolledCount" :label="'enrolled this term'" />
                    <p class="registrar-continuation-card__summary">
                        <span>{{ $didNotProceedCount }} did not proceed</span>@if ($inProgressCount > 0) · <span>{{ $inProgressCount }} still in progress</span>@endif
                    </p>
                    @if ($progressions !== [])
                        <div class="registrar-continuation-table-wrap" tabindex="0" role="region" aria-labelledby="registrar-continuation-heading">
                            <table class="registrar-continuation-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Year level</th>
                                        <th scope="col" class="is-num">Enrolled</th>
                                        <th scope="col" class="is-num">Did not proceed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($progressions as $row)
                                        <tr>
                                            <th scope="row">{{ $row['label'] }}</th>
                                            <td class="is-num">{{ $row['enrolled'] }}</td>
                                            <td class="is-num">{{ $row['did_not_proceed'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @elseif (filled($continuation['empty_note'] ?? null))
                        <p class="tala-workspace-card__note">{{ $continuation['empty_note'] }}</p>
                    @endif
                </div>
                <div class="tala-workspace-feature__action">
                    {{ $continuationBreakdownAction }}
                </div>
            </section>
        </nav>
    </section>
    <div class="tala-workspace-launcher__split">
        <section class="registrar-start-panel tala-task-surface" aria-labelledby="registrar-start-heading">
            <header class="registrar-start-panel__header">
                <h2 id="registrar-start-heading">Start enrollment</h2>
                <p>Choose who is enrolling.</p>
            </header>
            <div class="registrar-start-actions">
                {{ $continuingAction }} {{ $shiftProgramAction }} {{ $lateContinuingAction }}
            </div>
        </section>
        <x-workspace-card
            class="registrar-payment-queue-card"
            :href="$queueUrl('finance_pending')"
            icon="heroicon-o-banknotes"
            label="Students Payment in Queue"
            :figure="$paymentQueueTotal"
            :figure-label="\Illuminate\Support\Str::plural('student', $paymentQueueTotal).' awaiting payment or verification'"
            :description="$paymentQueueBreakdown"
            note="Review enrollments waiting for learner payment or Accounting fee verification."
            action="Open payment queue"
            action-color="gray"
            :heading-level="2"
        />
    </div>
    <section class="registrar-records-entry tala-task-surface" aria-labelledby="registrar-records-heading">
        <div><h2 id="registrar-records-heading">Enrollment records</h2><p>Find an enrollment, registration certificate, or previous change.</p></div>
        <x-filament::button tag="a" :href="$queueUrl('all_cases')" color="gray" icon="heroicon-o-magnifying-glass">Find a record</x-filament::button>
    </section>
</div>

<x-filament-actions::modals />
