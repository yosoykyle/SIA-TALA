@props(['result', 'showDetails' => true])

@if ($result['result'] === 'INC' && ! $showDetails)
    <x-filament::badge color="warning" id="course-result-{{ $result['course_enrollment']->id }}">INC</x-filament::badge>
@else
    <span id="course-result-{{ $result['course_enrollment']->id }}">{{ $result['result'] ?? 'Not released' }}</span>
@endif
@if ($result['result'] === 'INC' && $showDetails)
    <span class="student-academic-secondary">{{ ($result['inc_state'] ?? null) === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue ? 'Completion overdue' : 'Completion pending' }}@if ($result['inc_deadline'] ?? null) · Until {{ $result['inc_deadline']->format('M j, Y') }} (Asia/Manila)@endif</span>
    <x-supporting-details summary="INC details and next step" inset>
        <x-student-inc-guidance :$result />
    </x-supporting-details>
@endif
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
