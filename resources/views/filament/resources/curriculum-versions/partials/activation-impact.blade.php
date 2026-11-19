@php
    $intake = $curriculum->effectiveEntryTerm;
    $intakeLabel = $intake ? collect([$intake->label, $intake->academicYear?->label])->filter()->implode(', ') : null;
    $programName = $curriculum->program?->name ?? 'this program';
    $draftSubjects = $impact['draft_specifications_to_activate'] ?? [];
    $assigned = (int) $impact['existing_student_locks'];
@endphp

<div class="tala-activation-impact">
    @if ($impact['readiness_errors'] !== [])
        <x-tala-access-alert variant="danger" title="This curriculum can’t be activated yet" role="alert">
            <ul>
                @foreach ($impact['readiness_errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-tala-access-alert>
    @endif

    <ul class="tala-activation-impact__effects">
        <li>
            @if ($intakeLabel)
                New students starting <strong>{{ $intakeLabel }}</strong> will follow this curriculum.
            @else
                New students will follow this curriculum from now on. You can name the starting term below.
            @endif
        </li>
        <li>
            @if ($impact['active_version_name'] ?? null)
                It replaces <strong>{{ $impact['active_version_name'] }}</strong> for new students. That plan is kept as history.
            @else
                It becomes the first active curriculum for {{ $programName }}.
            @endif
        </li>
        <li>
            {{ match (true) {
                $assigned === 0 => 'No students are assigned to an earlier curriculum.',
                $assigned === 1 => '1 student already assigned to an earlier curriculum keeps their current plan.',
                default => $assigned.' students already assigned to an earlier curriculum keep their current plans.',
            } }}
        </li>
        @if ($impact['has_active_term_setup'] ?? false)
            <li>
                Active term classes and student sections recorded under the previous curriculum keep their setup, and continuing students can still enroll under their assigned plan.
            </li>
        @endif
        @if ($draftSubjects !== [])
            <li>
                It also makes {{ count($draftSubjects) }} draft {{ str('subject')->plural(count($draftSubjects)) }} in this plan active.
                <details class="tala-activation-impact__list">
                    <summary>Show {{ count($draftSubjects) === 1 ? 'subject' : 'subjects' }}</summary>
                    <ul>
                        @foreach ($draftSubjects as $draftSubjectLabel)
                            <li>{{ $draftSubjectLabel }}</li>
                        @endforeach
                    </ul>
                </details>
            </li>
        @endif
    </ul>
</div>
