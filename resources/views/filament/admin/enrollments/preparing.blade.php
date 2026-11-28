@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    @if ($case->admissionApplication?->application_path === \App\Models\AdmissionApplication::PathTransferee && $evaluation === null)
        <section class="registrar-requirements registrar-evaluation-task tala-task-surface" aria-labelledby="evaluation-heading">
            <h2 id="evaluation-heading">Record transfer credit evaluation</h2>
            <p>Use the school’s approved result before selecting subjects. Record a no-credit result when applicable.</p>
            <div class="registrar-case-task-actions">{{ $this->getAction('recordAcademicEvaluation', isMounting: false) }}</div>
            <div class="registrar-preparation-next"><h3>Next: choose subjects</h3><p>Subject selection becomes available after the evaluation is recorded.</p></div>
        </section>
    @else
    @php
        $irregularRequest = \App\Models\IrregularEnrollmentRequest::query()->where('enrollment_id', $case->id)->first();
        $irregularReview ??= $case->studentProfile !== null
            ? app(\App\Actions\Academics\IrregularStudentReasons::class)->forStudent($case->studentProfile, $case->term)
            : null;
    @endphp
    @if ($irregularRequest !== null)
        <section class="registrar-irregular-request tala-task-surface" aria-labelledby="irregular-request-heading">
            <h2 id="irregular-request-heading">Irregular application · {{ \App\Models\IrregularEnrollmentRequest::stateLabels()[$irregularRequest->state] ?? $irregularRequest->state }}</h2>
            <p class="registrar-case-meta">Submitted {{ $irregularRequest->submitted_at?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') }} · {{ count($irregularRequest->sectionIds()) }} {{ str('class')->plural(count($irregularRequest->sectionIds())) }} · {{ rtrim(rtrim((string) $irregularRequest->selected_units, '0'), '.') }} units</p>
            <x-irregular-reasons :review="['is_irregular' => true, 'kept_regular' => false, 'decision_reason' => null, 'status_label' => 'Irregular'.(($irregularRequest->reasons[0]['label'] ?? null) ? ' – '.$irregularRequest->reasons[0]['label'] : ''), 'reasons' => $irregularRequest->reasons ?? []]" />
            @if (filled($irregularRequest->student_remarks))<p><strong>Student remarks:</strong> {{ $irregularRequest->student_remarks }}</p>@endif
            @if (($irregularRequest->supporting_documents ?? []) !== [])<p><strong>Supporting documents:</strong> {{ count($irregularRequest->supporting_documents) }} attached</p>{{ $this->getAction('downloadIrregularDocuments', isMounting: false) }}@endif
            @if ($irregularRequest->state === \App\Models\IrregularEnrollmentRequest::StateReturned)<p><strong>Returned:</strong> {{ $irregularRequest->return_reason }}</p>@endif
            @if ($irregularRequest->state === \App\Models\IrregularEnrollmentRequest::StateSubmitted)
                <p>The requested classes are shown below. <strong>Approve subjects</strong> accepts the application; <strong>Adjust</strong> lets you change it first.</p>
                <div class="registrar-case-task-actions">{{ $this->getAction('returnIrregularRequest', isMounting: false) }}</div>
            @endif
        </section>
    @elseif (($irregularReview['is_irregular'] ?? false) === true)
        <section class="registrar-irregular-request tala-task-surface" aria-labelledby="irregular-status-heading">
            <h2 id="irregular-status-heading">Irregular status</h2>
            <x-irregular-reasons :review="$irregularReview" />
        </section>
    @endif
    @php $regularBlocker = $this->regularPreparationBlocker(); @endphp
    @if ($regularBlocker !== null)
        @include('filament.admin.enrollments.auto-preparation')
    @elseif (($suggestion = $this->advisedSuggestion()) !== null)
        @php
            $formatUnits = fn (?float $units): string => $units === null ? '' : rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');
            $fromRequest = $suggestion['source'] === \App\Actions\Enrollment\AdvisedSubjectSuggestion::SourceRequest;
            $hasPicks = $suggestion['picked'] !== [];
            $classDetails = app(\App\Actions\Enrollment\RegistrationClassChoices::class)->sectionDescriptions($case);
        @endphp
        <section class="registrar-requirements registrar-suggested-subjects tala-task-surface" aria-labelledby="suggested-subjects-heading">
            <div class="registrar-requirements-heading">
                <x-filament::icon :icon="$hasPicks ? 'heroicon-o-clipboard-document-check' : 'heroicon-o-exclamation-triangle'" aria-hidden="true" />
                <div>
                    <h2 id="suggested-subjects-heading">{{ $fromRequest ? 'Requested subjects' : ($hasPicks ? 'Suggested subjects' : 'No subjects could be suggested') }}</h2>
                    <p>{{ $fromRequest
                        ? 'The classes the student asked for in their irregular application.'
                        : ($hasPicks
                            ? 'Remaining subjects this student can take now: not credited or passed, prerequisites met, offered this term, no schedule clash and within the normal load. Lower years come first.'
                            : 'None of the remaining subjects can be taken as offered. The reasons are listed below; choose subjects to advise an alternative.') }}</p>
                </div>
            </div>
            @if ($hasPicks)
                <dl class="registrar-suggested-summary">
                    <div><dt>Subjects</dt><dd>{{ count($suggestion['picked']) }}</dd></div>
                    <div><dt>Units</dt><dd>{{ $formatUnits($suggestion['selected_units']) }}@if ($suggestion['normal_units'] !== null)<span> of {{ $formatUnits($suggestion['normal_units']) }}</span>@endif</dd></div>
                    @if ($suggestion['position_label'])<div><dt>Normal load from</dt><dd>{{ $suggestion['position_label'] }}</dd></div>@endif
                </dl>
                <ol class="registrar-suggested-list">
                    @foreach ($suggestion['picked'] as $row)
                        <li>
                            <p class="registrar-suggested-subject"><strong>{{ $row['code'] }}</strong> {{ $row['title'] }}</p>
                            <p class="registrar-suggested-class">Class {{ $row['class'] }} · {{ $classDetails[$row['section_id']] ?? $formatUnits($row['units']).' units' }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            @if ($suggestion['left_out'] !== [])
                <details class="registrar-suggested-left-out" @if (! $hasPicks) open @endif>
                    <summary>Not included <span>{{ count($suggestion['left_out']) }}</span></summary>
                    @foreach (collect($suggestion['left_out'])->groupBy('reason') as $reason => $rows)
                        <section class="registrar-suggested-reason-group" aria-label="{{ $reason }}">
                            <h3>{{ $reason }} <span>{{ $rows->count() }}</span></h3>
                            <ul>
                                @foreach ($rows as $row)
                                    <li><strong>{{ $row['code'] }}</strong> {{ $row['title'] }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </details>
            @endif
            <div class="registrar-case-task-actions">
                {{ $this->getAction('approveSuggestedSubjects', isMounting: false) }}
                {{ $this->getAction('prepareProposal', isMounting: false) }}
            </div>
            <p class="registrar-case-meta">{{ $hasPicks ? 'Nothing is reserved until you approve. Accounting then records the assessment, and the subjects go to the student automatically.' : 'No subjects selected yet.' }}</p>
        </section>
    @else
    <section class="registrar-case-preparation tala-task-surface" aria-labelledby="preparation-heading">
        <div><h2 id="preparation-heading">Prepare subjects</h2><p>{{ $case->selection_basis === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'This enrollment needs an individually advised subject selection.' : 'Prepare the complete curriculum subjects and classes for this enrollment.' }}</p></div>
        {{ $this->getAction('prepareProposal', isMounting: false) }}
    </section>
    <p class="registrar-case-meta">No subjects selected. Saving a selection prepares a draft for review.</p>
    @endif
    @endif
    <a class="registrar-case-history-link" {{ \Filament\Support\generate_href_html($this->enrollmentUrl(['view' => 'history'])) }}>
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
    <div class="registrar-case-progress-cancel">{{ $this->getAction('cancelCase', isMounting: false) }}</div>
</div>
