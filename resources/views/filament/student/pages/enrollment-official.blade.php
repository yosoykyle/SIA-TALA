<div class="student-registration student-registration-official">
    @if ($isStudent)
        <section class="tala-task-surface student-card--two-tone" aria-labelledby="official-status-heading">
            <div class="student-card__body">
                <div class="student-status-main">
                    <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-check-badge" />Enrollment status</p>
                    <h2 id="official-status-heading">Officially enrolled</h2>
                    <p class="student-tile-meta">{{ collect([
                        $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable',
                        $enrollment->currentCorVersion !== null ? 'COR-'.$enrollment->id.'-'.$enrollment->currentCorVersion->version : null,
                    ])->filter()->implode(' · ') }}</p>
                </div>
                <dl class="student-welcome-facts">
                    <div><dt>Term</dt><dd>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }}</dd></div>
                    <div><dt>School year</dt><dd>{{ $enrollment->term?->academicYear?->label ?? 'Unavailable' }}</dd></div>
                    @if ($enrollment->officially_enrolled_at)<div><dt>Enrolled</dt><dd>{{ $enrollment->officially_enrolled_at->timezone(config('app.display_timezone'))->format('M j, Y') }}</dd></div>@endif
                    <div><dt>Load</dt><dd>{{ (float) $registeredSubjects->sum('units_snapshot') }} units</dd></div>
                </dl>
            </div>
            <div class="student-card__footer">
                @if ($enrollment->current_cor_version_id)
                    <p class="student-tile-meta" style="margin: 0;">Your official Certificate of Registration is ready to view or print.</p>
                    <x-filament::button tag="a" :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')" icon="heroicon-o-document-text">View registration</x-filament::button>
                @else
                    <p class="student-tile-meta" style="margin: 0;">Your registration document is unavailable. Contact the Registrar.</p>
                @endif
            </div>
        </section>
    @else
    @include('filament.components.student-study-context', [
        'contextProgram' => $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable',
        'contextFacts' => array_filter([
            'Term' => \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable',
            'School year' => $enrollment->term?->academicYear?->label ?? 'Unavailable',
            'Enrolled' => $enrollment->officially_enrolled_at?->timezone(config('app.display_timezone'))->format('F j, Y'),
        ], fn (mixed $value): bool => $value !== null),
        'contextActionUrl' => $isStudent && $enrollment->current_cor_version_id ? \App\Filament\Student\Pages\CorView::getUrl(panel: 'student') : null,
        'contextActionMessage' => $isStudent && $enrollment->current_cor_version_id ? null : 'Your registration document is unavailable. Contact the Registrar.',
    ])
    @endif
    <section class="tala-task-surface student-card--two-tone" aria-labelledby="registered-subjects-heading">
        <div class="student-card__body">
            <div class="student-registration-subjects-heading" style="margin-block-end: 0;">
                <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem;">
                    <h2 class="student-record-heading" id="registered-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Registered subjects</h2>
                    <p style="margin: 0;">{{ $registeredSubjects->count() }} {{ $registeredSubjects->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) $registeredSubjects->sum('units_snapshot') }} units</p>
                </div>
                @if ($isStudent)<p class="student-registration-purpose" style="margin-block-start: 0.25rem;">These are the subjects and classes on your official registration. Your grades and study plan are in Grades.</p>@endif
            </div>
        </div>
        @if ($registeredSubjects->isNotEmpty())
            <div class="student-card__table-flush student-academic-table-wrap" role="region" aria-label="Registered subjects and classes" tabindex="0">
                <table class="tala-data-table student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Section / Modality</th><th scope="col">Units</th></tr></thead><tbody>
                    @foreach ($registeredSubjects as $subject)
                        @php
                            $modalityCode = $subject->section?->delivery_mode ?: $subject->termOffering?->modality;
                            $modalityLabel = match (strtoupper(str_replace([' ', '-'], '_', trim((string) $modalityCode)))) {
                                'FACE_TO_FACE' => 'Face-to-face',
                                'ONLINE' => 'Online',
                                'HYBRID' => 'Hybrid',
                                '' => null,
                                default => str((string) $modalityCode)->replace('_', ' ')->headline()->toString(),
                            };
                            $sectionCode = $subject->section?->code;
                        @endphp
                        <tr wire:key="registered-subject-{{ $subject->id }}"><th scope="row">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Subject unavailable' }}<span class="student-academic-secondary">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->course?->code }}</span></th><td>@if ($modalityLabel)<span>{{ $modalityLabel }}</span>@endif @if (filled($sectionCode))<span @class(['student-academic-secondary' => $modalityLabel !== null])>Sec. {{ $sectionCode }}</span>@elseif ($modalityLabel === null)Unavailable @endif</td><td class="student-academic-number">{{ (float) $subject->units_snapshot }}</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @else
            <div class="student-card__body" style="border-block-start: 1px solid var(--tala-edge);">
                <x-servitech-empty-state
                    illustrated
                    heading="No active subjects recorded"
                    description="No active subjects are recorded. Contact the Registrar if this looks incorrect."
                    heading-tag="h3"
                />
            </div>
        @endif
        @if ($isStudent && count($corHistory) > 1)
            <div class="student-card__footer">
                <p class="student-tile-meta" style="margin: 0;">Looking for a previous term or earlier registration copy?</p>
                <x-filament::button tag="a" color="gray" size="sm" :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')" icon="heroicon-o-clock">View earlier registrations</x-filament::button>
            </div>
        @endif
    </section>
    <x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
</div>
