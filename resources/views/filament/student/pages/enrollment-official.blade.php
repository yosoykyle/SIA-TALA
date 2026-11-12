<div class="student-registration student-registration-official">
    @if ($isStudent)
        <section class="student-tile student-tile-tint student-status-card" aria-labelledby="official-status-heading">
            <div class="student-status-main">
                <p class="student-tile-kicker"><x-filament::icon icon="heroicon-o-check-badge" />Enrollment status</p>
                <h2 id="official-status-heading">Officially enrolled</h2>
                <p class="student-tile-meta">{{ $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>
            </div>
            <dl class="student-status-facts">
                <div><dt>Term</dt><dd>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }}</dd></div>
                <div><dt>School year</dt><dd>{{ $enrollment->term?->academicYear?->label ?? 'Unavailable' }}</dd></div>
                @if ($enrollment->officially_enrolled_at)<div><dt>Enrolled</dt><dd>{{ $enrollment->officially_enrolled_at->timezone(config('app.display_timezone'))->format('M j, Y') }}</dd></div>@endif
                <div><dt>Load</dt><dd>{{ (float) $registeredSubjects->sum('units_snapshot') }} units</dd></div>
            </dl>
            <div class="student-tile-actions">
                @if ($enrollment->current_cor_version_id)
                    <x-filament::button tag="a" :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')" icon="heroicon-o-document-text">View registration</x-filament::button>
                @else
                    <p class="student-tile-meta">Your registration document is unavailable. Contact the Registrar.</p>
                @endif
                <x-filament::button tag="a" color="gray" icon="heroicon-o-calendar-days" :href="\App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')">View class schedule</x-filament::button>
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
    <section class="tala-task-surface" aria-labelledby="registered-subjects-heading">
        <div class="student-registration-subjects-heading"><h2 class="student-record-heading" id="registered-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Registered subjects</h2><p>{{ $registeredSubjects->count() }} {{ $registeredSubjects->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) $registeredSubjects->sum('units_snapshot') }} units</p>@if ($isStudent)<p class="student-registration-purpose">These are the subjects and classes on your official registration. Your grades and study plan are in Academics.</p>@endif</div>
        @if ($registeredSubjects->isNotEmpty())
            <div class="student-academic-table-wrap" role="region" aria-label="Registered subjects and classes" tabindex="0">
                <table class="student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th></tr></thead><tbody>
                    @foreach ($registeredSubjects as $subject)
                        <tr wire:key="registered-subject-{{ $subject->id }}"><th scope="row">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Subject unavailable' }}<span class="student-academic-secondary">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->course?->code }}</span></th><td>{{ $subject->section?->code ?? 'Unavailable' }}</td><td class="student-academic-number">{{ (float) $subject->units_snapshot }}</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @else<p>No active subjects are recorded. Contact the Registrar if this looks incorrect.</p>@endif
        @if ($isStudent)<div class="student-registration-links"><x-filament::button tag="a" color="gray" icon="heroicon-o-academic-cap" :href="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')">View grades and results</x-filament::button><x-filament::button tag="a" color="gray" icon="heroicon-o-banknotes" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button></div>@endif
        @if ($isStudent && count($corHistory) > 1)
            <p class="student-registration-help"><x-filament::link :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')">View earlier registrations</x-filament::link></p>
        @endif
        <p class="student-registration-help">Contact the Registrar to request a subject change or report an enrollment error.</p>
    </section>
    <x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
</div>
