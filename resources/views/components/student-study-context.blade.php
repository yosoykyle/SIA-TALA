@props(['context'])

<section class="student-study-context tala-task-surface" aria-label="Current studies">
    <div class="student-study-program">
        <p class="student-study-label">Program</p>
        <h2>{{ $context['program_name'] ?? 'Program unavailable' }}</h2>
    </div>
    <div class="student-study-level">
        <p class="student-study-label">Year level</p>
        <p class="student-study-value">{{ $context['study_level_label'] }}</p>
        <p class="student-study-note">{{ $context['is_official'] ? 'From your current subjects' : 'Available after official enrollment' }}</p>
    </div>
    <dl class="student-study-term">
        <div><dt>Term</dt><dd>{{ $context['semester'] ?? 'Unavailable' }}</dd></div>
        <div><dt>School year</dt><dd>{{ $context['academic_year'] ?? 'Unavailable' }}</dd></div>
    </dl>
</section>
