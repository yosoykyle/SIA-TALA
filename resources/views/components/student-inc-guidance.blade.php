@props(['result', 'showAverageNote' => true])

<div class="student-inc-guidance">
    @if ($result['event']?->inc_completion_note)
        <p class="student-inc-work">{{ $result['event']->inc_completion_note }}</p>
    @else
        <p>Ask your Faculty which remaining work you need to complete.</p>
    @endif
    @if ($result['inc_deadline'] ?? null)
        <p class="student-inc-deadline"><x-filament::icon icon="heroicon-o-calendar-days" /> Deadline: {{ $result['inc_deadline']->format('M j, Y') }} · Asia/Manila</p>
    @endif
    @if (($result['inc_state'] ?? null) === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue)
        <x-workflow-handoff compact current-owner="Registrar" current-area="Review completion options" current-task="Ordinary completion is closed. Ask the Registrar about retaking the subject or an authorized deadline extension." />
    @else
        <x-workflow-handoff compact current-owner="Student" current-area="Complete remaining work" current-task="Complete the remaining work with your Faculty." current-icon="heroicon-o-user" next-owner="Faculty" next-area="Review completion" next-task="Faculty records completion for Registrar review and release." next-icon="heroicon-o-user-group" />
    @endif
    @if ($showAverageNote)<p class="student-academic-note">{{ $result['average_exclusion'] ?? 'This INC contributes no grade points or units.' }}</p>@endif
    @foreach ($result['inc_amendments'] ?? [] as $amendment)
        <p class="student-academic-note">Deadline amended to {{ $amendment->new_deadline->format('M j, Y') }} · {{ $amendment->reason }}</p>
    @endforeach
</div>
