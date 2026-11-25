{{-- #99 Scope 11 · Enroll as irregular, step 1: who the student is and why they are irregular (locked). --}}
@php
    $student = $case->studentProfile;
    $lastEnrollment = $student?->enrollments()->whereNotNull('officially_enrolled_at')->with('term.academicYear')->get()
        ->sortByDesc(fn ($enrollment) => (string) $enrollment->term?->starts_on?->toDateString())->first();
    $studyContext = $student?->user ? app(\App\Actions\StudentHub\StudentStudyContext::class)->forStudent($student->user) : [];
@endphp
<div class="tala-irregular-request-step">
    <dl class="tala-irregular-request-facts" aria-label="Student information">
        <div><dt>Student number</dt><dd>{{ $student?->student_number ?? 'Not recorded' }}</dd></div>
        <div><dt>Full name</dt><dd>{{ collect([$student?->first_name, $student?->middle_name, $student?->last_name])->filter()->implode(' ') }}</dd></div>
        <div><dt>Program</dt><dd>{{ $student?->program?->name ?? 'Not recorded' }}</dd></div>
        <div><dt>Current year level</dt><dd>{{ $studyContext['study_level_label'] ?? 'Not recorded' }}</dd></div>
        <div><dt>Current status</dt><dd>{{ $review['status_label'] }}</dd></div>
        <div><dt>Last enrolled term</dt><dd>{{ $lastEnrollment ? collect([$lastEnrollment->term?->label, $lastEnrollment->term?->academicYear?->label])->filter()->implode(' ') : 'Not recorded' }}</dd></div>
    </dl>
    <h3 class="tala-irregular-request-subheading">Irregular reason (set by the Registrar; cannot be edited)</h3>
    <x-irregular-reasons :review="$review" audience="student" />
</div>
