@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $summary = $overviewSummary ?? [];
    $assessmentCount = (int) ($counts['assessment'] ?? 0);
    $proofCount = (int) ($counts['proof'] ?? 0);
    $changesCount = (int) ($counts['student_changes'] ?? 0);
    $paymentCount = $proofCount;
    $uniqueReviewCount = array_key_exists('uniqueReviewCount', $summary)
        ? (int) $summary['uniqueReviewCount']
        : ($assessmentCount + $paymentCount + $changesCount);
    $hasPendingWork = $uniqueReviewCount > 0;
    $priorityStep = match (true) {
        $paymentCount > 0 => 'payments',
        $assessmentCount > 0 => 'assessment',
        $changesCount > 0 => 'student_changes',
        default => null,
    };
    $draftPlans = (int) ($summary['feePlanDraftCount'] ?? 0);
    $publishedPlans = (int) ($summary['feePlanPublishedCount'] ?? 0);
    $allPublishedPlans = (int) ($summary['feePlanAllPublishedCount'] ?? $publishedPlans);
    $activeTermLabel = $summary['activeTermLabel'] ?? 'No active term';
    $activeTermNames = array_values(array_filter(
        $summary['activeTermNames'] ?? (filled($summary['activeTermLabel'] ?? null) && ($summary['activeTermId'] ?? null) !== null ? [$summary['activeTermLabel']] : []),
    ));
    $openAccounts = (int) ($summary['openAccountsCount'] ?? 0);
    $torOpen = (int) ($summary['torOpenCount'] ?? 0);
    $torRestricted = (int) ($summary['torRestrictedCount'] ?? 0);
    $queueUrl = fn (string $key): string => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $key]);
@endphp
<div class="accounting-workbench">
    <section aria-labelledby="accounting-attention-heading">
        <header @class(['tala-task-surface', 'accounting-overview-strip', 'accounting-overview-strip--active' => $hasPendingWork])>
            <div class="accounting-overview-strip__lead">
                <span class="tala-workspace-card__icon" aria-hidden="true">
                    <x-filament::icon :icon="$hasPendingWork ? 'heroicon-o-inbox-stack' : 'heroicon-o-check-circle'" />
                </span>
                <div>
                    <h2 id="accounting-attention-heading">
                        @if ($hasPendingWork)
                            {{ $uniqueReviewCount }} {{ \Illuminate\Support\Str::plural('account', $uniqueReviewCount) }} {{ $uniqueReviewCount === 1 ? 'needs' : 'need' }} review
                        @else
                            No account reviews waiting
                        @endif
                    </h2>
                    <p class="accounting-overview-strip__meta">Across all active and prior terms</p>
                </div>
            </div>
            <dl class="accounting-overview-strip__metrics">
                <div>
                    <dt>Open term accounts</dt>
                    <dd>{{ $openAccounts }}</dd>
                </div>
                <div @class(['is-warning' => $torRestricted > 0])>
                    <dt>TOR restrictions</dt>
                    <dd>{{ $torRestricted }}</dd>
                </div>
            </dl>
        </header>
        <p class="tala-workspace-launcher__scope accounting-workflow-caption">
            @if ($activeTermNames !== [])
                Active terms: {{ implode(' · ', $activeTermNames) }}
            @else
                No active term. Earlier accounts remain available for review.
            @endif
        </p>
        <ul class="tala-workflow-steps" aria-label="Accounting tasks">
            <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityStep === 'assessment', 'tala-workflow-step--active' => $assessmentCount > 0 && $priorityStep !== 'assessment', 'tala-workflow-step--caught-up' => $assessmentCount === 0])>
                <div class="accounting-step-body">
                    <div class="tala-workflow-step__head">
                        <span class="tala-workspace-card__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-document-currency-dollar" />
                        </span>
                    </div>
                    <h3 class="tala-workflow-step__title">Assessment reviews</h3>
                    <div class="tala-workflow-step__figure">
                        <x-workspace-figure :value="$assessmentCount" :label="\Illuminate\Support\Str::plural('account', $assessmentCount)" />
                    </div>
                    <p class="tala-workflow-step__desc">Review charges, subject changes and earlier payments.</p>
                </div>
                <div class="tala-workflow-step__action tala-workspace-feature__action">
                    <x-filament::button tag="a" :href="$queueUrl('assessment_required')" :color="$priorityStep === 'assessment' ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after">Review assessments</x-filament::button>
                </div>
            </li>
            <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityStep === 'payments', 'tala-workflow-step--caught-up' => $paymentCount === 0])>
                <div class="accounting-step-body">
                    <div class="tala-workflow-step__head">
                        <span class="tala-workspace-card__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-document-magnifying-glass" />
                        </span>
                    </div>
                    <h3 class="tala-workflow-step__title">Submitted receipts</h3>
                    <div class="tala-workflow-step__figure">
                        <x-workspace-figure :value="$proofCount" :label="\Illuminate\Support\Str::plural('account', $proofCount)" />
                    </div>
                    <p class="tala-workflow-step__desc">Check transfer receipts before verifying payment.</p>
                </div>
                <div class="tala-workflow-step__action tala-workspace-feature__action">
                    <x-filament::button tag="a" :href="$queueUrl('payment_proof')" :color="$priorityStep === 'payments' ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after">Review receipts</x-filament::button>
                </div>
            </li>
            <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityStep === 'student_changes', 'tala-workflow-step--active' => $changesCount > 0 && $priorityStep !== 'student_changes', 'tala-workflow-step--caught-up' => $changesCount === 0])>
                <div class="accounting-step-body">
                    <div class="tala-workflow-step__head">
                        <span class="tala-workspace-card__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-clipboard-document-check" />
                        </span>
                    </div>
                    <h3 class="tala-workflow-step__title">Student status changes</h3>
                    <div class="tala-workflow-step__figure">
                        <x-workspace-figure :value="$changesCount" :label="\Illuminate\Support\Str::plural('account', $changesCount)" />
                    </div>
                    <p class="tala-workflow-step__desc">Review account effects of applied student changes.</p>
                </div>
                <div class="tala-workflow-step__action tala-workspace-feature__action">
                    <x-filament::button tag="a" :href="$queueUrl('student_changes')" :color="$priorityStep === 'student_changes' ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after">Review changes</x-filament::button>
                </div>
            </li>
            <li @class(['tala-workflow-step', 'tala-workflow-step--active' => $allPublishedPlans > 0 || $draftPlans > 0, 'tala-workflow-step--caught-up' => $allPublishedPlans === 0 && $draftPlans === 0])>
                <div class="accounting-step-body">
                    <div class="tala-workflow-step__head">
                        <span class="tala-workspace-card__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-calculator" />
                        </span>
                    </div>
                    <h3 class="tala-workflow-step__title">Fee plans</h3>
                    <div class="tala-workflow-step__figure">
                        <x-workspace-figure :value="$allPublishedPlans" label="published" />
                    </div>
                    <p class="tala-workflow-step__desc">Set tuition, fees and payment due dates.</p>
                    <p class="tala-workflow-step__meta">All terms · {{ $draftPlans }} {{ \Illuminate\Support\Str::plural('draft', $draftPlans) }}</p>
                    @if (($summary['activeTermId'] ?? null) !== null && $allPublishedPlans !== $publishedPlans)
                        <p class="tala-workflow-step__meta">{{ $activeTermLabel }}: {{ $publishedPlans }} published</p>
                    @endif
                    <p class="tala-workflow-step__meta">
                        <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html(\App\Filament\Pages\PaymentChannels::getUrl()) }}>Payment channels <x-filament::icon icon="heroicon-m-arrow-right" aria-hidden="true" /></a>
                    </p>
                </div>
                <div class="tala-workflow-step__action tala-workspace-feature__action">
                    <x-filament::button tag="a" :href="\App\Filament\Resources\FeePlans\FeePlanResource::getUrl('index')" color="gray" icon="heroicon-m-arrow-right" icon-position="after">Review fee plans</x-filament::button>
                </div>
            </li>
        </ul>
    </section>
    <div class="accounting-overview-tools">
        <form method="get" action="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index') }}" class="tala-task-surface accounting-student-lookup" aria-labelledby="accounting-student-lookup-label">
            <input type="hidden" name="tab" value="accounts">
            <label id="accounting-student-lookup-label" for="accounting-student-lookup-input" class="accounting-student-lookup__label">
                <span class="tala-workspace-card__icon" aria-hidden="true">
                    <x-filament::icon icon="heroicon-o-users" />
                </span>
                <span>Find a student</span>
            </label>
            <div class="accounting-student-lookup__row">
                <div class="accounting-student-lookup__field">
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input type="search" name="search" id="accounting-student-lookup-input" placeholder="Name or student number" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="submit" color="gray" icon="heroicon-m-arrow-right" icon-position="after" class="accounting-student-lookup__submit">Search accounts</x-filament::button>
            </div>
        </form>
        <nav class="tala-task-surface accounting-records-launcher" aria-label="Accounts and transcript restrictions">
            <a {{ \Filament\Support\generate_href_html($queueUrl('accounts')) }} class="tala-workspace-card tala-workspace-card--compact accounting-record-link">
                <span class="tala-workspace-card__icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-folder-open" /></span>
                <span class="tala-workspace-card__content">
                    <span class="tala-workspace-card__title">All student accounts</span>
                    <span class="tala-workspace-card__description">Check balances, record onsite payments and manage coverage.</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="tala-workspace-card__arrow" aria-hidden="true" />
            </a>
            <a {{ \Filament\Support\generate_href_html(\App\Filament\Resources\TranscriptRequests\TranscriptRequestResource::getUrl('index')) }} class="tala-workspace-card tala-workspace-card--compact accounting-record-link">
                <span class="tala-workspace-card__icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-shield-exclamation" /></span>
                <span class="tala-workspace-card__content">
                    <span class="tala-workspace-card__title">TOR restrictions</span>
                    <span class="tala-workspace-card__description">Review payment holds on Transcript of Records (TOR) requests.</span>
                    <span class="tala-workspace-card__note">{{ $torRestricted }} active {{ \Illuminate\Support\Str::plural('hold', $torRestricted) }} · {{ $torOpen }} open {{ \Illuminate\Support\Str::plural('request', $torOpen) }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="tala-workspace-card__arrow" aria-hidden="true" />
            </a>
        </nav>
    </div>
</div>
<x-filament-actions::modals />
