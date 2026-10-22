@props(['result'])

<span>{{ $result['result'] ?? 'Not released' }}</span>
@if (in_array($result['course_enrollment']->status, [\App\Models\CourseEnrollment::StatusDropped, \App\Models\CourseEnrollment::StatusWithdrawn], true))
    <span class="student-academic-secondary">{{ str($result['course_enrollment']->status)->headline() }}</span>
@elseif (! $result['course_enrollment']->is_current)
    <span class="student-academic-secondary">Earlier registration</span>
@endif
@if ($result['event'] && $result['event']->event_type !== \App\Models\GradeOutcomeEvent::TypeInitialRelease)
    <span class="student-academic-secondary">{{ match ($result['event']->event_type) {
        \App\Models\GradeOutcomeEvent::TypePostedCorrection => 'Corrected result',
        \App\Models\GradeOutcomeEvent::TypeIncResolution => 'INC completed',
        default => str($result['event']->event_type)->replace('_', ' ')->headline(),
    } }}</span>
@endif
