<x-filament-panels::page class="tala-term-workspace tala-timetable-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $isTermClosed = $this->isTermClosed();
            $workingRun = $allRuns->first(fn ($run) => in_array($run->status, [
                \App\Models\ScheduleGenerationRun::StatusUnderReview,
                \App\Models\ScheduleGenerationRun::StatusQueued,
                \App\Models\ScheduleGenerationRun::StatusDispatching,
            ], true) && ! in_array($run->candidate_state, ['Rejected', 'Stale', 'Superseded'], true));
            $reviewEvidence = $activeRun?->reviewEvidence();
            $hasTimetableRecord = $activeRun || ($showOfficial && $selectedVersion);
            $pendingGeneration = ! $showOfficial && $activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true);
            $isOfficialPublished = $showOfficial && $selectedVersion && $selectedVersion->state === \App\Models\PublishedTimetableVersion::StatePublished;
            $isOfficialSuperseded = $showOfficial && $selectedVersion && $selectedVersion->state !== \App\Models\PublishedTimetableVersion::StatePublished;
            $timetableStateLabel = $showOfficial && $selectedVersion
                ? ($isOfficialPublished
                    ? ($isTermClosed
                        ? 'Official timetable · Archived'
                        : ($versions->count() > 1 ? 'Official timetable · Revision '.$selectedVersion->version : 'Official timetable'))
                    : 'Historical official · Revision '.$selectedVersion->version)
                : ($activeRun ? match ($activeRun->candidate_state) {
                    'Accepted' => 'Draft accepted',
                    'Rejected' => 'Past attempt · Discarded',
                    'Stale', 'Superseded' => 'Past attempt · Needs current sources',
                    default => match ($activeRun->status) {
                        \App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching => 'Timetable generation in progress',
                        \App\Models\ScheduleGenerationRun::StatusFailed => 'Past attempt · Failed',
                        \App\Models\ScheduleGenerationRun::StatusBlocked => 'Past attempt · Blocked',
                        \App\Models\ScheduleGenerationRun::StatusUnderReview => 'Draft timetable · Under review',
                        default => \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status,
                    },
                } : null);
            $isEditableDraft = (bool) ($interactiveContext['editable'] ?? false);
            $isPastAttempt = ! $showOfficial && $activeRun && ! $pendingGeneration && ! $isEditableDraft;
            $isPostEnrollmentLocked = (bool) ($hasOfficialEnrollmentsBlockingRegen ?? false);
            $hasActiveDraft = ! empty($activeDraftOptions);
            $activeDraftOption = $hasActiveDraft ? $activeDraftOptions[0] : null;
            $stableOverviewId = 'timetable-overview-'.($showOfficial ? 'official-'.$selectedVersion?->id : 'draft-'.$term->id);
            $canReviseOfficial = $showOfficial && $selectedVersion && $this->canReviseOfficialSchedule();
            $hasSolverIssue = ! $showOfficial && ($solverFailure || ! empty($solverOutcome['message']) || in_array($reviewEvidence['outcome'] ?? '', ['Infeasible', 'Unknown', 'ModelInvalid', 'TechnicalFailure'], true));
            $plainFailure = $hasSolverIssue ? $this->plainSolverFailureSummary($solverFailure ?? [], $solverOutcome ?? [], $reviewEvidence) : null;
            $canPublishDraft = ! $readOnly && ! $isTermClosed && ! $isPostEnrollmentLocked && $activeRun && ! $showOfficial && $this->canPublishActiveRun();
            $showToolbarNewDraft = ! $readOnly && ! $isTermClosed && ($canStartNewDraftAttempt ?? false)
                && $isPastAttempt && $scopeMeetingCount > 0;
        @endphp
        <div class="tala-timetable-studio">
            @if ($hasTimetableRecord)
                <div class="tala-timetable-toolbar flex items-center justify-between gap-3" aria-label="Timetable actions and record controls">
                    <div class="tala-timetable-toolbar__status flex items-center gap-2">
                        <div class="tala-guide-filter tala-studio-source">
                            <label for="timetable-source" class="tala-studio-source__label">Viewing:</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="timetable-source" wire:change="chooseTimetable($event.target.value)">
                                    @if ($versions->isNotEmpty())
                                        <optgroup label="Official schedule">
                                            @foreach ($versions as $version)
                                                <option value="official:{{ $version->id }}" @selected($showOfficial && $selectedVersion?->id === $version->id)>{{ $version->state === 'Published' ? ($isTermClosed ? 'Official timetable (Archived · Rev '.$version->version.')' : ($versions->count() > 1 ? 'Official timetable (Live · Rev '.$version->version.')' : 'Official timetable (Live)')) : 'Historical official · Rev '.$version->version }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if (! empty($activeDraftOptions))
                                        <optgroup label="Active draft">
                                            @foreach ($activeDraftOptions as $option)
                                                <option value="draft:{{ $option['id'] }}" @selected(! $showOfficial && $activeRun?->id === $option['id'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if (! empty($pastAttemptOptions))
                                        <optgroup label="Recent past attempts">
                                            @foreach ($pastAttemptOptions as $option)
                                                <option value="draft:{{ $option['id'] }}" @selected(! $showOfficial && $activeRun?->id === $option['id'])>{{ $option['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        @if ($isOfficialPublished && ! $isTermClosed)
                            <span
                                class="tala-timetable-status-pill is-official"
                                title="Official live schedule: use Revise schedule for approved changes"
                            >
                                <x-filament::icon icon="heroicon-m-lock-closed" />
                                <span>Official · Live · {{ $scopeMeetingCount }} {{ \Illuminate\Support\Str::plural('meeting', $scopeMeetingCount) }}</span>
                            </span>
                        @elseif ($isOfficialPublished && $isTermClosed)
                            <span
                                class="tala-timetable-status-pill is-archived"
                                title="Term is closed: read-only historical official schedule"
                            >
                                <x-filament::icon icon="heroicon-m-archive-box" />
                                <span>Official · Archived · {{ $scopeMeetingCount }} {{ \Illuminate\Support\Str::plural('meeting', $scopeMeetingCount) }}</span>
                            </span>
                        @elseif ($isOfficialSuperseded)
                            <span
                                class="tala-timetable-status-pill is-archived"
                                title="Superseded historical version: read-only"
                            >
                                <x-filament::icon icon="heroicon-m-clock" />
                                <span>Historical · Rev {{ $selectedVersion->version }} · {{ $scopeMeetingCount }} {{ \Illuminate\Support\Str::plural('meeting', $scopeMeetingCount) }}</span>
                            </span>
                        @elseif ($pendingGeneration)
                            <span class="tala-timetable-status-pill is-draft">
                                <x-filament::icon icon="heroicon-m-clock" />
                                <span>Generating · Attempt #{{ $activeRun->id }}</span>
                            </span>
                        @elseif (! $showOfficial && $activeRun && $isEditableDraft)
                            <span class="tala-timetable-status-pill is-draft">
                                <x-filament::icon icon="heroicon-m-pencil-square" />
                                <span>Draft · Editable</span>
                            </span>
                            @php
                                $draftValidated = ($reviewEvidence['validated'] ?? false) === true;
                                $draftConflictCount = (int) ($activeRun->publicationSummary()['conflicts'] ?? 0);
                            @endphp
                            <button
                                type="button"
                                class="tala-timetable-status-pill is-check is-interactive {{ $draftValidated ? 'is-pass' : 'is-warn' }}"
                                title="Click to inspect verification checks"
                                x-on:click="$dispatch('open-modal', { id: 'timetable-review-details' })"
                            >
                                <x-filament::icon :icon="$draftValidated ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle'" />
                                <span>{{ $draftValidated ? 'Verified · 0 conflicts' : ($draftConflictCount > 0 ? $draftConflictCount.' '.\Illuminate\Support\Str::plural('conflict', $draftConflictCount) : 'Needs check') }}</span>
                            </button>
                        @elseif ($isPastAttempt)
                            <span class="tala-timetable-status-pill is-archived">
                                <x-filament::icon icon="heroicon-m-clock" />
                                <span>Past attempt · Read-only</span>
                            </span>
                        @endif
                    </div>

                    <div class="tala-studio-viewing">
                        @if ($canPublishDraft)
                            {{ $this->publishOfficialTimetableAction->size('sm') }}
                        @elseif (! $readOnly && ! $isTermClosed && $activeRun && ! $showOfficial && $this->canRetryActiveRun() && $scopeMeetingCount > 0)
                            {{ $this->retrySolverRunAction->size('sm') }}
                        @elseif ($canReviseOfficial)
                            {{ $this->revisePublishedScheduleAction->size('sm') }}
                        @endif

                        @if ($showToolbarNewDraft)
                            <x-filament::button
                                type="button"
                                color="primary"
                                size="sm"
                                icon="heroicon-o-sparkles"
                                wire:click="openGenerateFromDetails"
                            >
                                New draft
                            </x-filament::button>
                        @endif

                        @if ($showOfficial && $selectedVersion)
                            <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $selectedVersion, 'timetableView' => $timetableView, 'timetableFormat' => $timetableFormat, 'viewFilter' => $viewFilter, 'programFilter' => $programFilter, 'yearFilter' => $yearFilter, 'tableSearch' => $tableSearch])" target="_blank" title="Print whole term">Print<span class="sr-only"> whole term</span></x-filament::button>
                        @elseif ($activeRun && ! $pendingGeneration)
                            <x-filament::button type="button" color="gray" size="sm" icon="heroicon-o-printer" x-on:click="window.print()" title="Print draft timetable">Print<span class="sr-only"> draft timetable</span></x-filament::button>
                        @endif

                        <x-filament::dropdown placement="bottom-end" width="xs">
                            <x-slot name="trigger">
                                <x-filament::button color="gray" size="sm" icon="heroicon-o-ellipsis-horizontal" aria-label="More timetable actions">More</x-filament::button>
                            </x-slot>
                            <x-filament::dropdown.list>
                                @if ($showOfficial && $selectedVersion)
                                    <x-filament::dropdown.list.item
                                        tag="a"
                                        icon="heroicon-o-document-text"
                                        :href="$this->timetableReviewUrl($selectedVersion->schedule_run_id)"
                                    >
                                        Solver report
                                    </x-filament::dropdown.list.item>
                                @elseif ($activeRun)
                                    <x-filament::dropdown.list.item
                                        tag="a"
                                        icon="heroicon-o-document-text"
                                        :href="$this->timetableReviewUrl($activeRun->id)"
                                    >
                                        Solver report
                                    </x-filament::dropdown.list.item>
                                @endif
                                @if (! $pendingGeneration)
                                    <x-filament::dropdown.list.item
                                        icon="heroicon-o-clipboard-document-check"
                                        x-on:click="close(); $dispatch('open-modal', { id: 'timetable-review-details' })"
                                    >
                                        Verification &amp; history
                                    </x-filament::dropdown.list.item>
                                @endif
                                @if ($canReviseOfficial)
                                    <x-filament::dropdown.list.item
                                        icon="heroicon-o-x-circle"
                                        color="danger"
                                        wire:click="mountAction('cancelPublishedClass')"
                                        x-on:click="close()"
                                    >
                                        Cancel class
                                    </x-filament::dropdown.list.item>
                                @endif
                                @if (! $readOnly && ! $isTermClosed && $activeRun && ! $showOfficial && $this->canReviewActiveRun())
                                    <x-filament::dropdown.list.item
                                        icon="heroicon-o-x-circle"
                                        color="danger"
                                        wire:click="mountAction('rejectCandidate')"
                                        x-on:click="close()"
                                    >
                                        Discard draft
                                    </x-filament::dropdown.list.item>
                                @endif
                                @if (! $readOnly && ! $isTermClosed && $activeRun && ! $showOfficial && $this->canRetryActiveRun() && $canPublishDraft)
                                    <x-filament::dropdown.list.item
                                        icon="heroicon-o-arrow-path"
                                        wire:click="mountAction('retrySolverRun')"
                                        x-on:click="close()"
                                    >
                                        Retry generation
                                    </x-filament::dropdown.list.item>
                                @endif
                            </x-filament::dropdown.list>
                        </x-filament::dropdown>
                    </div>
                </div>

                @if ($isEditableDraft && $isPostEnrollmentLocked)
                    <section class="tala-studio-context-strip is-warning" role="status" aria-label="Whole-term publication locked">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                            <span>Whole-term publishing and regeneration are locked because students are officially enrolled. Use <strong>Revise schedule</strong> on the official timetable for approved changes.</span>
                        </div>
                        @if ($currentVersion)
                            <x-filament::button color="gray" size="sm" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')">View official</x-filament::button>
                        @endif
                    </section>
                @elseif ($isEditableDraft && $currentVersion)
                    <section class="tala-studio-context-strip is-neutral" role="status" aria-label="Draft editing notice">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                            <span>Editing unpublished draft: <strong>Official Rev {{ $currentVersion->version }}</strong> stays live until you publish.</span>
                        </div>
                        <x-filament::button color="gray" size="sm" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')">View official</x-filament::button>
                    </section>
                @elseif ($isOfficialPublished && ! $isTermClosed && $isPostEnrollmentLocked)
                    <section class="tala-studio-context-strip is-neutral" role="status" aria-label="Whole-term draft generation locked">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-lock-closed" aria-hidden="true" />
                            <span>Whole-term draft generation is locked because active student enrollments exist for this term. Use <strong>Revise schedule</strong> for approved changes.</span>
                        </div>
                    </section>
                @elseif ($isOfficialPublished && ! $isTermClosed && ! $isPostEnrollmentLocked && $activeDraftOption !== null)
                    <section class="tala-studio-context-strip is-warning" role="status" aria-label="Unpublished draft in progress">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-pencil-square" aria-hidden="true" />
                            <span>Unpublished draft (<strong>Attempt #{{ $activeDraftOption['id'] }}</strong>) is in progress. Official stays live until published.</span>
                        </div>
                        <x-filament::button color="gray" size="sm" wire:click="chooseTimetable('draft:{{ $activeDraftOption['id'] }}')">Open draft</x-filament::button>
                    </section>
                @elseif ($isOfficialPublished && ! $isTermClosed && ! $isPostEnrollmentLocked && ! $hasActiveDraft && ($canStartNewDraftAttempt ?? false))
                    <section class="tala-studio-context-strip is-neutral" role="status" aria-label="Pre-enrollment draft option">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-sparkles" aria-hidden="true" />
                            <span>No active student enrollments yet. Start a new whole-term draft anytime without affecting the live schedule until you publish.</span>
                        </div>
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-sparkles"
                            wire:click="openGenerateFromDetails"
                        >
                            New draft
                        </x-filament::button>
                    </section>
                @elseif ($isPastAttempt && $scopeMeetingCount > 0)
                    <section class="tala-studio-context-strip is-neutral" role="status" aria-label="Past attempt notice">
                        <div class="tala-studio-context-strip__body">
                            <x-filament::icon icon="heroicon-o-clock" aria-hidden="true" />
                            <span>Viewing a past attempt (<strong>Attempt #{{ $activeRun->id }}</strong> · {{ $this->plainAttemptStatusLabel($activeRun) }}). Past attempts are read-only and cannot be published.</span>
                        </div>
                        @if ($activeDraftOption !== null)
                            <x-filament::button color="gray" size="sm" wire:click="chooseTimetable('draft:{{ $activeDraftOption['id'] }}')">Open draft</x-filament::button>
                        @elseif ($currentVersion)
                            <x-filament::button color="gray" size="sm" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')">View official</x-filament::button>
                        @endif
                    </section>
                @endif
            @endif

            @if ($showOfficial && $selectedVersion && ($pendingDraftRevision ?? null))
                <section class="tala-studio-pending-revision" role="status" aria-label="Pending schedule revision">
                    <div class="tala-studio-pending-revision__body">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                        <div>
                            <strong>Pending schedule revision #{{ $pendingDraftRevision->id }} awaiting publication</strong>
                            <p>The current official timetable remains active until affected student enrollment reviews are resolved and this revision is published.</p>
                        </div>
                    </div>
                    @if ($canReviseOfficial)
                        {{ $this->publishPendingRevisionAction->size('sm') }}
                    @else
                        <x-filament::button
                            tag="a"
                            size="sm"
                            color="gray"
                            icon="heroicon-o-document-text"
                            :href="$this->timetableReviewUrl($selectedVersion->schedule_run_id)"
                        >
                            View audit record
                        </x-filament::button>
                    @endif
                </section>
            @endif

            @php
                $isEmptyFailedDraft = ! $showOfficial && $activeRun && ! $pendingGeneration && ! ($timetablePresentation && $scopeMeetingCount);
                $failureOutcome = (string) ($reviewEvidence['outcome'] ?? '');
                $failureTechnical = (string) ($plainFailure['technical'] ?? '');
                $failureRawLower = strtolower($failureTechnical.' '.($solverFailure['code'] ?? '').' '.($solverOutcome['solver_status'] ?? ''));
                $isConnectionFailure = str_contains($failureRawLower, 'request failed')
                    || str_contains($failureRawLower, 'authentication failed')
                    || str_contains($failureRawLower, 'not configured')
                    || str_contains($failureRawLower, 'probe failed')
                    || str_contains($failureRawLower, 'timed out')
                    || str_contains($failureRawLower, 'timeout')
                    || str_contains($failureRawLower, '503')
                    || str_contains($failureRawLower, 'unavailable')
                    || str_contains($failureRawLower, 'transport')
                    || str_contains($failureRawLower, 'connection');
                $isErrorSeverity = $isConnectionFailure
                    || ($activeRun?->status === \App\Models\ScheduleGenerationRun::StatusFailed)
                    || in_array($failureOutcome, ['TechnicalFailure', 'ModelInvalid'], true);
                $failureAlertTitle = match (true) {
                    $isConnectionFailure => 'Scheduling service unavailable',
                    $failureOutcome === 'Infeasible' => 'No valid timetable found',
                    $failureOutcome === 'ModelInvalid' => 'Schedule data could not be processed',
                    $failureOutcome === 'Unknown' => 'Timetable search did not finish',
                    default => 'Timetable could not be generated',
                };
            @endphp

            @if ($hasTimetableRecord)
                @php
                    $hasDraftValidationWarning = $activeRun && ! $showOfficial && ! $pendingGeneration && ! $isEmptyFailedDraft && ! ($reviewEvidence['validated'] ?? false);
                    $showTopFailureCard = $plainFailure !== null && ! $pendingGeneration && ! $isEmptyFailedDraft;
                    $hasVisibleStatusStrip = $hasDraftValidationWarning || $showTopFailureCard;
                @endphp
                <div wire:loading.delay.long wire:target="chooseTimetable,showTimetableView,showTimetableFormat,viewFilter,programFilter,yearFilter,tableSearch,clearViewFilters,moveCandidateMeeting,updateCandidateMeeting,togglePinMeeting,undoLastChangeFromNotification" class="tala-studio-status" role="status"><p class="tala-studio-message"><x-filament::loading-indicator aria-hidden="true" />Updating timetable…</p></div>
                @if ($hasVisibleStatusStrip)
                    <section class="tala-studio-status" aria-label="Timetable result">
                        @if ($showTopFailureCard)
                            <div @class(['tala-studio-alert-card', 'is-error' => $isErrorSeverity, 'is-warning' => ! $isErrorSeverity]) role="alert">
                                <div class="tala-studio-alert-card__body">
                                    <span class="tala-studio-alert-card__icon" aria-hidden="true">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                                    </span>
                                    <div class="tala-studio-alert-card__copy">
                                        <p class="tala-studio-alert-card__title">{{ $failureAlertTitle }}</p>
                                        <p class="tala-studio-alert-card__text">{{ $plainFailure['explanation'] }} {{ $plainFailure['nextStep'] }}</p>
                                    </div>
                                </div>
                                @if ($failureTechnical !== '')
                                    <div class="tala-studio-alert-card__footer">
                                        <span class="tala-studio-alert-card__ref-label">Error reference:</span>
                                        <code class="tala-studio-alert-card__ref">{{ $failureTechnical }}</code>
                                    </div>
                                @endif
                            </div>
                        @elseif ($hasDraftValidationWarning)
                            <div class="tala-studio-alert-card is-warning" role="status">
                                <div class="tala-studio-alert-card__body">
                                    <span class="tala-studio-alert-card__icon" aria-hidden="true">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                                    </span>
                                    <div class="tala-studio-alert-card__copy">
                                        <p class="tala-studio-alert-card__title">{{ \Illuminate\Support\Str::before($reviewEvidence['coverage'], ' independently validated at the recorded check') }} · {{ $reviewEvidence['conflicts'] }}.</p>
                                        <p class="tala-studio-alert-card__text">{{ $reviewEvidence['outcome'] }}. <button type="button" class="tala-studio-alert-card__link" x-on:click="$dispatch('open-modal', { id: 'timetable-review-details' })">View evidence</button></p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </section>
                @endif
            @endif

            <section class="tala-studio-board" aria-label="Scheduled meetings">
                @if ($pendingGeneration)
                    <div class="tala-studio-canvas is-generating" wire:key="timetable-board-generating-{{ $activeRun->id }}">
                        <x-tala-operation-status :run="$activeRun" in-canvas wire:poll.5s.visible>
                            @if ($this->cancelActiveRunAction->isVisible())
                                <x-slot name="actions">
                                    {{ $this->cancelActiveRunAction }}
                                </x-slot>
                            @endif
                        </x-tala-operation-status>
                    </div>
                @elseif ($timetablePresentation && $scopeMeetingCount)
                    @php
                        $showDockRegenerate = $isEditableDraft && ! $readOnly && ! $isTermClosed && ! $isPostEnrollmentLocked
                            && $this->regenerateTimetableAction->isVisible();
                        $showDraftDockActions = ! $readOnly && ! $isTermClosed && $activeRun && ! $showOfficial
                            && ($this->canUndoLastChange() || $this->canResetToSolverDraft() || $showDockRegenerate);
                        $hasDraftDock = $showDraftDockActions;
                    @endphp
                    <div
                        @class(['tala-studio-canvas', 'is-week' => $timetableFormat === 'week', 'has-draft-dock' => $hasDraftDock])
                        wire:key="timetable-board-grid-{{ $showOfficial ? 'official-'.($selectedVersion?->id ?? '0') : 'draft-'.($activeRun?->id ?? '0') }}"
                    >
                        @if (! count($timetablePresentation['meetings']))
                            <p class="tala-studio-empty-filter">No meetings match this view. <x-filament::button color="gray" size="sm" wire:click="clearViewFilters">Clear filters</x-filament::button></p>
                        @elseif (in_array($timetableView, ['whole', 'section', 'faculty', 'room']) && ($timetableView === 'whole' || $viewFilter))
                            <x-tala-timetable
                                :presentation="$timetablePresentation"
                                :interactive="true"
                                :editable="$isEditableDraft"
                                :is-official="$showOfficial"
                                :schedule-context="$interactiveContext ?? []"
                                :initial-view="$timetableView"
                                :initial-format="$timetableFormat"
                                :initial-entity="$viewFilter ?? ''"
                                :initial-program="$programFilter ?? ''"
                                :initial-year="$yearFilter ?? ''"
                                :initial-search="$tableSearch ?? ''"
                                :overview-id="$stableOverviewId"
                                focused
                                controlled
                                embedded
                                :show-window="false"
                                wire:key="guide-week-{{ $term?->id }}-{{ $showOfficial ? 'official-'.$selectedVersion?->id : 'draft-'.$activeRun?->id }}"
                            >
                                @if ($showDraftDockActions)
                                    <x-slot name="draftDock">
                                        @if ($this->canUndoLastChange())
                                            {{ $this->undoLastChangeAction->size('sm')->extraAttributes(['class' => 'tala-dock-btn tala-dock-btn--neutral']) }}
                                        @endif
                                        @if ($this->canResetToSolverDraft())
                                            {{ $this->resetToSolverDraftAction->size('sm')->extraAttributes(['class' => 'tala-dock-btn tala-dock-btn--warning']) }}
                                        @endif
                                        @if ($showDockRegenerate)
                                            {{ $this->regenerateTimetableAction->size('sm')->extraAttributes(['class' => 'tala-dock-btn tala-dock-btn--neutral']) }}
                                        @endif
                                    </x-slot>
                                @endif
                            </x-tala-timetable>
                        @else
                            <p class="tala-studio-empty-filter">{{ match ($timetableView) {'section' => 'Choose a student section to see its weekly meetings.', 'faculty' => 'Choose a teacher to see their weekly meetings.', default => 'Choose a room to see its weekly meetings.'} }}</p>
                        @endif
                    </div>
                @else
                    @php
                        $readiness = $generationReadiness ?? $this->generationReadiness();
                        $readinessBlockers = $readiness['blockers'] ?? [];
                        $canStartFreshAttempt = ! $activeRun
                            || in_array($activeRun->status, [
                                \App\Models\ScheduleGenerationRun::StatusFailed,
                                \App\Models\ScheduleGenerationRun::StatusBlocked,
                                \App\Models\ScheduleGenerationRun::StatusSuperseded,
                            ], true)
                            || in_array($activeRun->candidate_state, ['Rejected', 'Stale', 'Superseded'], true);
                        $isDraftEmptyHero = (bool) ($activeRun && ! $showOfficial);
                        $isErrorEmptyHero = $isDraftEmptyHero && (
                            $isErrorSeverity
                            || $hasSolverIssue
                            || in_array($activeRun->status, [
                                \App\Models\ScheduleGenerationRun::StatusFailed,
                                \App\Models\ScheduleGenerationRun::StatusBlocked,
                            ], true)
                        );
                        $emptyHeroTone = $isErrorEmptyHero ? ($isErrorSeverity ? 'danger' : 'warning') : ($isDraftEmptyHero ? 'neutral' : 'default');
                        $emptyStageMood = match ($emptyHeroTone) {
                            'danger' => 'error',
                            'warning' => 'stalled',
                            default => 'calm',
                        };
                        $solverJsPath = public_path('js/tala-solver-stage.js');
                        $solverJsUrl = asset('js/tala-solver-stage.js').'?v='.(is_file($solverJsPath) ? filemtime($solverJsPath) : '1');
                        $emptyDescription = $isDraftEmptyHero
                            ? ($plainFailure['explanation'] ?? $reviewEvidence['meaning'] ?? 'Inspect the recorded findings and adjust the inputs before trying again.')
                            : ($readiness['ready']
                                ? 'All term inputs are ready for timetable generation.'
                                : (count($readinessBlockers) > 1
                                    ? 'Resolve '.count($readinessBlockers).' remaining prerequisites below before generating a whole-term timetable.'
                                    : ($readiness['reason'] ?? 'Complete the remaining prerequisite before generating a timetable.')));
                        $emptyHeroHeading = $isDraftEmptyHero
                            ? (in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) ? 'Preparing your timetable' : 'No timetable was created')
                            : 'No timetable yet';
                    @endphp
                    <div
                        @class([
                            'tala-guide-empty-work',
                            'tala-timetable-empty-stage',
                            'is-error-stage' => $emptyHeroTone === 'danger',
                            'is-warning-stage' => $emptyHeroTone === 'warning',
                        ])
                        wire:key="timetable-board-empty-{{ $activeRun?->id ?? 'none' }}"
                        data-tala-ambient-stage
                        data-stage-mood="{{ $emptyStageMood }}"
                        data-script-url="{{ $solverJsUrl }}"
                        x-data="{
                            init() {
                                if (window.talaAmbientStageMount) {
                                    window.talaAmbientStageMount(this.$el);
                                    return;
                                }
                                if (!document.querySelector('script[data-tala-solver-stage-loader]')) {
                                    const script = document.createElement('script');
                                    script.src = this.$el.dataset.scriptUrl;
                                    script.dataset.talaSolverStageLoader = 'true';
                                    script.onload = () => window.talaAmbientStageMount && window.talaAmbientStageMount(this.$el);
                                    document.head.appendChild(script);
                                }
                            }
                        }"
                    >
                        <x-tala-ambient-backdrop variant="stage" wire:ignore />
                        <div class="tala-timetable-empty-stage__content">
                            @if ($isDraftEmptyHero)
                                @php
                                    $canRetryHero = ! $readOnly && ! $isTermClosed && $this->canRetryActiveRun();
                                    $heroKickerLabel = match (true) {
                                        $isErrorSeverity => 'Attempt #'.$activeRun->id.' · '.($failureAlertTitle ?? 'Generation failed'),
                                        $isErrorEmptyHero => 'Attempt #'.$activeRun->id.' · Needs review',
                                        default => 'Attempt #'.$activeRun->id.' · '.$this->plainAttemptStatusLabel($activeRun),
                                    };
                                    $heroCombinedBody = trim($emptyDescription.' '.(($plainFailure !== null && ($plainFailure['nextStep'] ?? '') !== '' && ! str_contains($emptyDescription, (string) $plainFailure['nextStep'])) ? $plainFailure['nextStep'] : ''));
                                @endphp
                                <div @class(['tala-error-stage', 'is-danger' => $emptyHeroTone === 'danger', 'is-warning' => $emptyHeroTone === 'warning']) role="status">
                                    <div class="tala-error-stage__icon" aria-hidden="true">
                                        <x-filament::icon :icon="$isErrorEmptyHero ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clock'" />
                                    </div>
                                    <p class="tala-error-stage__kicker">{{ $heroKickerLabel }}</p>
                                    <h2 class="tala-error-stage__heading">{{ $emptyHeroHeading }}</h2>
                                    <p class="tala-error-stage__body">{{ $heroCombinedBody }}</p>
                                    <div class="tala-error-stage__actions">
                                        @if ($canRetryHero)
                                            {{ $this->retrySolverRunAction }}
                                        @elseif ($canStartFreshAttempt && ! $readOnly && ! $this->isTermClosed() && $readiness['ready'])
                                            {{ $this->generateTimetableAction }}
                                        @endif
                                        <x-filament::button tag="a" color="gray" icon="heroicon-o-document-text" :href="$this->timetableReviewUrl($activeRun->id)">Open solver report</x-filament::button>
                                    </div>
                                    @if ($plainFailure !== null && ($failureTechnical ?? '') !== '')
                                        <p class="tala-error-stage__ref">
                                            <span class="tala-error-stage__ref-label">Error reference</span>
                                            <code class="tala-error-stage__ref-code">{{ $failureTechnical }}</code>
                                        </p>
                                    @endif
                                </div>
                            @else
                                <x-servitech-empty-state
                                    :illustrated="true"
                                    :heading="$emptyHeroHeading"
                                    :description="$emptyDescription"
                                >
                                    @if ($canStartFreshAttempt && ! $readOnly && ! $this->isTermClosed() && $readiness['ready']){{ $this->generateTimetableAction }}@endif
                                </x-servitech-empty-state>
                            @endif

                            @if ($canStartFreshAttempt && ! $readOnly && ! $this->isTermClosed() && ! $readiness['ready'] && $readinessBlockers !== [])
                                @php
                                    $groupedBlockers = collect($readinessBlockers)
                                        ->groupBy(fn (array $blocker): string => ($blocker['code'] ?? $blocker['label']).'|'.($blocker['url'] ?? ''))
                                        ->map(function ($group): array {
                                            $first = $group->first();

                                            return [
                                                'label' => $first['label'],
                                                'url' => $first['url'],
                                                'reasons' => $group->pluck('reason')->unique()->values()->all(),
                                            ];
                                        })
                                        ->values();
                                @endphp
                                <div class="tala-timetable-prerequisites" aria-label="Timetable prerequisites checklist">
                                    <div class="tala-timetable-prerequisites__head">
                                        <h3>{{ count($readinessBlockers) === 1 ? '1 prerequisite remaining' : count($readinessBlockers).' prerequisites remaining' }}</h3>
                                        <p>Complete each step below before generating a whole-term timetable.</p>
                                    </div>
                                    <ul class="tala-guide-history" aria-label="Timetable prerequisites">
                                        @foreach ($groupedBlockers as $blockerGroup)
                                            @php
                                                $blockerStepLabel = match ($blockerGroup['label']) {
                                                    'Term Calendar', 'Term calendar' => 'Calendar',
                                                    'Classes', 'Classes & sections' => 'Classes & sections',
                                                    'Resources', 'Faculty & rooms' => 'Faculty & rooms',
                                                    'Timetable' => 'Timetable',
                                                    default => $blockerGroup['label'],
                                                };
                                            @endphp
                                            <li data-blocker-label="{{ $blockerGroup['label'] }}">
                                                @if (count($blockerGroup['reasons']) === 1)
                                                    <span>{{ $blockerGroup['reasons'][0] }}</span>
                                                @else
                                                    <div class="grid gap-1">
                                                        <strong>{{ count($blockerGroup['reasons']) }} items in {{ $blockerStepLabel }}</strong>
                                                        <ul class="list-disc ps-4 text-xs text-[var(--tala-muted)] grid gap-0.5">
                                                            @foreach ($blockerGroup['reasons'] as $reasonLine)
                                                                <li>{{ $reasonLine }}</li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                                <x-filament::button
                                                    tag="a"
                                                    size="sm"
                                                    :color="$loop->first ? 'primary' : 'gray'"
                                                    :icon="match ($blockerGroup['label']) { 'Classes', 'Classes & sections' => 'heroicon-o-rectangle-group', 'Resources', 'Faculty & rooms' => 'heroicon-o-users', 'Timetable' => 'heroicon-o-table-cells', default => 'heroicon-o-calendar' }"
                                                    :href="$blockerGroup['url']"
                                                >
                                                    {{ $blockerStepLabel }}
                                                </x-filament::button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </div>
                    <script src="{{ $solverJsUrl }}" data-navigate-once></script>
                @endif
            </section>

            @php
                $postPublishLinks = ($showOfficial && $selectedVersion && $selectedVersion->state === \App\Models\PublishedTimetableVersion::StatePublished && ! $this->isTermClosed())
                    ? $this->postPublishHandoffLinks()
                    : [];
            @endphp
            @if ($postPublishLinks !== [])
                <nav class="tala-timetable-next-strip" aria-label="Next steps after timetable publication">
                    <span class="tala-timetable-next-strip__lead">
                        <x-filament::icon icon="heroicon-m-check-circle" aria-hidden="true" />
                        <strong>Next up:</strong>
                        <span>Prepare operational windows and student intake</span>
                    </span>
                    <div class="tala-timetable-next-strip__links">
                        @foreach ($postPublishLinks as $nextLink)
                            <a href="{{ $nextLink['url'] }}" class="tala-timetable-next-strip__link">
                                <x-filament::icon :icon="$nextLink['icon']" aria-hidden="true" />
                                <span>{{ $nextLink['label'] }}</span>
                                <span class="tala-timetable-next-strip__arrow" aria-hidden="true">&rarr;</span>
                            </a>
                        @endforeach
                    </div>
                </nav>
            @endif

            @if ($hasTimetableRecord)
                <x-filament::modal footer-actions-alignment="end" id="timetable-review-details" width="4xl" teleport="body">
                    <x-slot name="heading">Verification &amp; history</x-slot>
                    <div class="tala-studio-details">
                        @php
                            $evidenceRun = $showOfficial && $selectedVersion ? $selectedVersion->scheduleRun : $activeRun;
                            $evidenceSummary = $evidenceRun ? $evidenceRun->reviewEvidence() : $reviewEvidence;
                            $outcomeCode = $pendingGeneration ? 'In progress' : ($evidenceSummary['outcome'] ?? 'Unknown');
                            $hardChecksPassed = ($showOfficial && $selectedVersion) || (($evidenceSummary['validated'] ?? false) === true);
                            $needsCurrentSources = ! $showOfficial
                                && ! $hardChecksPassed
                                && ! $pendingGeneration
                                && $evidenceRun
                                && (
                                    in_array($evidenceRun->candidate_state, ['Stale', 'Superseded'], true)
                                    || $evidenceRun->status === \App\Models\ScheduleGenerationRun::StatusSuperseded
                                    || in_array($outcomeCode, ['Optimal', 'Feasible', 'Local adjustment'], true)
                                );
                            $verificationStateLabel = $hardChecksPassed
                                ? 'Verified'
                                : ($pendingGeneration ? 'Pending' : ($needsCurrentSources ? 'Needs current sources' : match ($outcomeCode) {
                                    'Infeasible' => 'Conflicting inputs',
                                    'TechnicalFailure' => 'Attempt failed',
                                    'ModelInvalid' => 'Model invalid',
                                    default => 'Needs current sources',
                                }));
                            $verificationStateColor = $hardChecksPassed
                                ? 'success'
                                : ($pendingGeneration ? 'gray' : (in_array($outcomeCode, ['Infeasible', 'TechnicalFailure', 'ModelInvalid'], true) ? 'danger' : 'warning'));
                            $outcomeBadgeLabel = $hardChecksPassed ? match ($outcomeCode) {
                                'Local adjustment' => 'Edited draft',
                                'Optimal' => 'Solver optimal',
                                'Feasible' => 'Solver feasible',
                                default => null,
                            } : null;
                            $outcomeExplainer = $pendingGeneration
                                ? 'Timetable generation is still running. Constraint verification checks will complete automatically once the solver finishes.'
                                : ($hardChecksPassed
                                    ? match ($outcomeCode) {
                                        'Optimal' => 'Optimal means every hard rule passed (zero teacher, room, or section overlaps) and the solver proved no cleaner arrangement exists for minimizing student idle gaps and mode switches.',
                                        'Feasible' => 'Feasible means every hard rule passed with zero overlaps, though the solver stopped before proving whether an even tighter arrangement exists.',
                                        'Local adjustment' => 'Manually adjusted schedule verified against all hard rules (zero overlaps, valid rooms, and qualified teachers).',
                                        default => 'Issued official schedule verified against recorded term teaching hours, rooms, and faculty assignments.',
                                    }
                                    : ($needsCurrentSources
                                        ? 'Term inputs changed after this attempt was recorded, or a newer timetable attempt superseded it. Revalidate against current term sources before publishing.'
                                        : match ($outcomeCode) {
                                            'Infeasible' => 'Infeasible means at least two recorded requirements conflict (for example, more classes than eligible teacher units or rooms). Fix the flagged source and generate again.',
                                            default => 'This attempt does not establish a conflict-free whole-term schedule under current term sources.',
                                        }));
                            $runtimeLabel = match (true) {
                                $evidenceRun?->runtime_ms === null => 'Not measured',
                                $evidenceRun->runtime_ms < 1000 => $evidenceRun->runtime_ms.' ms (< 1 second)',
                                $evidenceRun->runtime_ms < 60000 => number_format($evidenceRun->runtime_ms / 1000, 1).' seconds',
                                default => floor($evidenceRun->runtime_ms / 60000).'m '.floor(($evidenceRun->runtime_ms % 60000) / 1000).'s',
                            };
                            $completenessText = $showOfficial && $selectedVersion
                                ? $scopeMeetingCount.' official '.\Illuminate\Support\Str::plural('meeting', $scopeMeetingCount).' issued for this term.'
                                : (str_contains($evidenceSummary['coverage'] ?? '', 'not established')
                                    ? \App\Actions\Scheduling\TimetableFailureLanguage::meetingCompletenessNotEstablished()
                                    : ($evidenceSummary['coverage'] ?? 'Not established for current term sources.'));
                            $conflictStatusText = str_contains($evidenceSummary['conflicts'] ?? '', 'not established')
                                ? \App\Actions\Scheduling\TimetableFailureLanguage::conflictChecksNotEstablished()
                                : ($evidenceSummary['conflicts'] ?? 'Revalidate with current term sources before publishing.');
                            $canGenerateInline = ! $readOnly
                                && ! $isTermClosed
                                && ! $isPostEnrollmentLocked
                                && ! $hasActiveDraft
                                && ! $pendingGeneration
                                && ! $this->retainedCandidateNextStep()
                                && ($activeRun || $versions->isNotEmpty() || ($generationReadiness ?? $this->generationReadiness())['ready']);
                        @endphp
                        <section class="tala-studio-evidence" aria-label="Timetable result and next action">
                            <div class="tala-evidence-title">
                                <x-filament::icon :icon="$showOfficial ? 'heroicon-o-check-badge' : 'heroicon-o-document-text'" />
                                <div>
                                    <h2>{{ $timetableStateLabel }}</h2>
                                    <p class="tala-work-footnote">
                                        @if ($showOfficial)
                                            Issued timetable{{ $selectedVersion?->published_at ? ' · '.$selectedVersion->published_at->timezone('Asia/Manila')->format('M j, Y g:i A') : '' }}
                                        @else
                                            Attempt #{{ $activeRun?->id ?? '-' }} · Not official
                                        @endif
                                        @if ($evidenceRun?->runtime_ms !== null)
                                            · Solved in {{ $runtimeLabel }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <section class="tala-evidence-surface" aria-label="Plain-language verification checks">
                                <div class="tala-evidence-outcome-row">
                                    <div>
                                        <h3>Verification summary</h3>
                                        <p class="tala-work-footnote">{{ $outcomeExplainer }}</p>
                                    </div>
                                    <div class="tala-evidence-outcome-badge">
                                        <x-filament::badge :color="$verificationStateColor" size="sm">
                                            {{ $verificationStateLabel }}
                                        </x-filament::badge>
                                        @if ($outcomeBadgeLabel !== null)
                                            <x-filament::badge color="gray" size="sm">
                                                {{ $outcomeBadgeLabel }}
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                </div>
                                <ul class="tala-evidence-checklist">
                                    @if ($hardChecksPassed)
                                        <li class="is-pass">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Zero teacher overlaps:</strong> No faculty member is scheduled for two classes at the same time.</span>
                                        </li>
                                        <li class="is-pass">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Zero room &amp; section conflicts:</strong> Every on-campus meeting fits its room capacity and type with no double-booking.</span>
                                        </li>
                                        <li class="is-pass">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Faculty availability &amp; unit limits:</strong> Assigned classes respect declared teaching hours and term load caps.</span>
                                        </li>
                                        <li class="is-pass">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Meeting completeness:</strong> {{ $completenessText }}</span>
                                        </li>
                                    @elseif ($pendingGeneration)
                                        <li class="is-neutral">
                                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Conflict &amp; capacity verification:</strong> Teacher, room, and student-section checks run automatically when solving finishes.</span>
                                        </li>
                                        <li class="is-neutral">
                                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Meeting completeness:</strong> Verifies every required class meeting is placed once the solver completes.</span>
                                        </li>
                                    @else
                                        <li class="is-pending">
                                            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Current-term revalidation required:</strong> {{ $needsCurrentSources ? 'Term sources changed after this attempt was generated, or a newer attempt superseded it.' : 'Hard-rule conflict and completeness checks were not established for this attempt.' }}</span>
                                        </li>
                                        <li class="is-neutral">
                                            <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Meeting completeness:</strong> {{ $completenessText }}</span>
                                        </li>
                                        <li class="is-neutral">
                                            <x-filament::icon icon="heroicon-o-shield-exclamation" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span><strong>Independent conflict status:</strong> {{ $conflictStatusText }}</span>
                                        </li>
                                    @endif
                                    <li class="is-neutral">
                                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        <span><strong>Solver runtime:</strong> {{ $runtimeLabel }}</span>
                                    </li>
                                </ul>
                            </section>

                            @if ($timetablePresentation['synthetic'] ?? false)<p class="tala-work-footnote">Preview dataset · Synthetic review data</p>@endif
                            @if ($showOfficial && $selectedVersion)
                                <section class="tala-evidence-surface" aria-label="Publication facts">
                                    <h3>Official record <x-filament::badge :color="$selectedVersion->state === 'Published' ? 'success' : 'gray'">{{ $selectedVersion->state }}</x-filament::badge></h3>
                                    <dl class="tala-evidence-facts">
                                        <div><dt>Published</dt><dd>{{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd></div>
                                        @if ($selectedVersion->authority_reference)<div><dt>Approval note / memo</dt><dd>{{ $selectedVersion->authority_reference }}</dd></div>@endif
                                    </dl>
                                    <p class="tala-work-footnote">Changes are prepared and reviewed separately so active student enrollments stay protected.</p>
                                </section>
                                <div class="tala-evidence-actions">
                                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $selectedVersion, 'timetableView' => $timetableView, 'timetableFormat' => $timetableFormat, 'viewFilter' => $viewFilter, 'programFilter' => $programFilter, 'yearFilter' => $yearFilter, 'tableSearch' => $tableSearch])" target="_blank">Print whole term</x-filament::button>
                                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square" :href="$this->timetableReviewUrl($selectedVersion->schedule_run_id)">Open solver report</x-filament::button>
                                    @if ($canGenerateInline)
                                        <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles" wire:click="openGenerateFromDetails">New draft</x-filament::button>
                                    @endif
                                </div>
                            @elseif ($activeRun)
                                <div class="tala-evidence-actions">
                                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square" :href="$this->timetableReviewUrl($activeRun->id)">
                                        Open solver report
                                    </x-filament::button>
                                    @if ($canGenerateInline)
                                        <x-filament::button color="gray" size="sm" icon="heroicon-o-sparkles" wire:click="openGenerateFromDetails">New draft</x-filament::button>
                                    @endif
                                </div>
                            @endif
                            @if ($showOfficial && $workingRun)
                                <div class="tala-evidence-source-switch"><div><h3>Active draft (Attempt #{{ $workingRun->id }})</h3><p class="tala-work-footnote">Review unpublished changes separately from the official timetable.</p></div><x-filament::button color="gray" size="sm" icon="heroicon-o-pencil-square" wire:click="chooseTimetable('draft:{{ $workingRun->id }}')" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })">Open draft</x-filament::button></div>
                            @elseif (! $showOfficial && $currentVersion)
                                <div class="tala-evidence-source-switch"><div><h3>Current official timetable</h3><p class="tala-work-footnote">The published timetable stays live until replaced.</p></div><x-filament::button color="gray" size="sm" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })">View official</x-filament::button></div>
                            @endif
                        </section>
                        @if ($activeRun && ! $showOfficial)
                            @if ($canPublishDraft)<p class="tala-work-footnote">Ready to publish. Publication rechecks independent validation, source freshness and Registrar authority for the whole term.@if ($currentVersion) The current official timetable stays live until published.@endif</p>@endif
                            @if (! $readOnly && ! $isTermClosed && $this->retainedCandidateNextStep())<p class="tala-work-footnote" role="status">{{ $this->retainedCandidateNextStep() }}</p>@endif
                        @endif
                        @if ($activeRun && ! $showOfficial)
                            <section class="tala-evidence-surface" aria-label="Result measures">
                                <h3>Result measures</h3>
                                <p class="tala-work-footnote">Attempt runtime: {{ $activeRun->runtime_ms === null ? 'Not measured' : number_format($activeRun->runtime_ms).' ms' }}</p>
                                <dl class="tala-evidence-facts">@foreach ($activeRun->qualityPresentation() as $measure)<div><dt>{{ $measure['label'] }}</dt><dd>{{ $measure['value'] }}</dd></div>@endforeach</dl>
                                <p class="tala-work-footnote">Faculty load imbalance includes every eligible teacher captured by the attempt, including teachers with no assignments.</p>
                            </section>
                        @endif
                        @php
                            $historyRuns = ($nonPublishedRuns ?? $allRuns->where('status', '!=', 'published')
                                ->filter(fn ($run) => ($activeRun?->id === $run->id) || ! \App\Actions\Scheduling\RevertCandidateRun::isIntermediateAdjustmentSnapshot($run)))
                                ->values();
                        @endphp
                        @if ($historyRuns->count() > 1 || $versions->count() > 1)
                            <section class="tala-evidence-surface" aria-label="Earlier timetable history">
                                <h3>Earlier timetable history</h3>
                                <p class="tala-work-footnote">Opening an earlier result does not change the official timetable.</p>
                                <ul class="tala-guide-history">@foreach ($historyRuns->take(10) as $run)<li><span><strong>Attempt #{{ $run->id }}</strong> · {{ $run->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $this->plainAttemptStatusLabel($run) }}</span><x-filament::button color="gray" size="sm" wire:click="chooseTimetable('draft:{{ $run->id }}')" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })" :disabled="! $showOfficial && $activeRun?->id === $run->id">{{ ! $showOfficial && $activeRun?->id === $run->id ? 'Viewing this attempt' : 'Open attempt' }}</x-filament::button></li>@endforeach
                                @foreach ($versions as $version)<li><span><strong>{{ $version->state }} v{{ $version->version }}</strong> · {{ $version->published_at?->timezone('Asia/Manila')->format('M j, Y') }}</span><x-filament::button color="gray" size="sm" wire:click="chooseTimetable('official:{{ $version->id }}')" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })" :disabled="$showOfficial && $selectedVersion?->id === $version->id">{{ $showOfficial && $selectedVersion?->id === $version->id ? 'Viewing this version' : 'Open version' }}</x-filament::button></li>@endforeach</ul>
                                @if ($historyRuns->count() > 10)
                                    <p class="tala-work-footnote">
                                        Showing latest 10 attempts of {{ $historyRuns->count() }}.
                                        <a class="fi-link" href="{{ \App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('index', ['termId' => $term?->id]) }}">View full solver history</a>
                                    </p>
                                @endif
                            </section>
                        @endif
                    </div>
                    <x-slot name="footerActions"><x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })">Close</x-filament::button></x-slot>
                </x-filament::modal>
            @endif
            @include('filament.pages.partials.timetable-generation-failure-modal')
        </div>
    @else
        <section class="tala-task-surface" aria-label="Term timetable">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-table-cells"
                heading="Choose a term"
                description="Choose a term above to view or generate its timetable."
            />
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
