<div class="student-registration student-registration-official">
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
    <section class="tala-task-surface" aria-labelledby="registered-subjects-heading">
        <div class="student-registration-subjects-heading"><h2 class="student-record-heading" id="registered-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Registered subjects</h2><p>{{ $registeredSubjects->count() }} {{ $registeredSubjects->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) $registeredSubjects->sum('units_snapshot') }} units</p></div>
        @if ($registeredSubjects->isNotEmpty())
            <div class="student-academic-table-wrap" role="region" aria-label="Registered subjects and classes" tabindex="0">
                <table class="student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th></tr></thead><tbody>
                    @foreach ($registeredSubjects as $subject)
                        <tr wire:key="registered-subject-{{ $subject->id }}"><th scope="row">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Subject unavailable' }}<span class="student-academic-secondary">{{ $subject->termOffering?->curriculumEntry?->courseSpecification?->course?->code }}</span></th><td>{{ $subject->section?->code ?? 'Unavailable' }}</td><td class="student-academic-number">{{ (float) $subject->units_snapshot }}</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @else<p>No active subjects are recorded. Contact the Registrar if this looks incorrect.</p>@endif
        @if ($isStudent)<div class="student-registration-links"><x-filament::button tag="a" color="gray" icon="heroicon-o-calendar-days" :href="\App\Filament\Student\Pages\ScheduleView::getUrl(panel: 'student')">View class schedule</x-filament::button><x-filament::button tag="a" color="gray" icon="heroicon-o-banknotes" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button></div>@endif
    </section>
    @if ($isStudent && count($corHistory) > 1)
        <p class="student-registration-help"><x-filament::link :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')">View earlier registrations</x-filament::link></p>
    @endif
    <p class="student-registration-help">Contact the Registrar to request a subject change or report an enrollment error.</p>
    <x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
</div>
