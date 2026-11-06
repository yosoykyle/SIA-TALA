@props(['result'])

<span id="course-result-{{ $result['course_enrollment']->id }}">{{ $result['result'] ?? 'Not released' }}</span>
@if ($result['result'] === 'INC')
    <span class="student-academic-secondary">{{ ($result['inc_state'] ?? null) === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue ? 'Completion overdue' : 'Completion pending' }}@if ($result['inc_deadline'] ?? null) · Until {{ $result['inc_deadline']->format('M j, Y') }} (Asia/Manila)@endif</span>
    <x-supporting-details summary="INC details and next step" inset>
        @if ($result['event']?->inc_completion_note)<p>{{ $result['event']->inc_completion_note }}</p>@endif
        <p>{{ ($result['inc_state'] ?? null) === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue ? 'Ordinary completion is closed. Ask the Registrar about retaking the subject or an authorized deadline extension.' : 'Complete the remaining work with your Faculty and ask the Registrar about recording completion.' }}</p>
        <p>{{ $result['average_exclusion'] ?? 'This INC contributes no grade points or units.' }}</p>
        @foreach ($result['inc_amendments'] ?? [] as $amendment)
            <p>Deadline amended to {{ $amendment->new_deadline->format('M j, Y') }} · {{ $amendment->reason }}</p>
        @endforeach
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
