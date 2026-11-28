@php
    $curriculum->loadMissing('batches.academicYear');
    $intake = $curriculum->effectiveEntryTerm;
    $intakeLabel = $intake ? collect([$intake->label, $intake->academicYear?->label])->filter()->implode(', ') : null;
    $programName = $curriculum->program?->name ?? 'this program';
    $draftSubjects = $impact['draft_specifications_to_activate'] ?? [];
    $assigned = (int) $impact['existing_student_locks'];
    $hasAssignedBatches = $curriculum->batches->isNotEmpty();
    $replacesActiveVersion = ! $hasAssignedBatches && filled($impact['active_version_name'] ?? null);
    $hasCoexistingActiveCurricula = $hasAssignedBatches
        && \App\Models\CurriculumVersion::query()
            ->where('program_id', $curriculum->program_id)
            ->where('state', \App\Models\CurriculumVersion::StateActive)
            ->whereKeyNot($curriculum->id)
            ->exists();
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
            @if ($hasAssignedBatches && $intakeLabel)
                New students starting <strong>{{ $intakeLabel }}</strong> ({{ $curriculum->batchLabel() }}) will follow this curriculum.
            @elseif ($hasAssignedBatches)
                Students in <strong>{{ $curriculum->batchLabel() }}</strong> will follow this curriculum. You can optionally name the starting term below.
            @elseif ($intakeLabel)
                New students starting <strong>{{ $intakeLabel }}</strong> will follow this curriculum.
            @else
                New students will follow this curriculum from now on. You can name the starting term below.
            @endif
        </li>
        <li>
            @if ($replacesActiveVersion)
                It replaces <strong>{{ $impact['active_version_name'] }}</strong> for new students. That plan is kept as history.
            @elseif ($hasCoexistingActiveCurricula)
                Existing active curricula for {{ $programName }} remain active for their assigned batches and are not replaced.
            @elseif ($hasAssignedBatches)
                It becomes an active curriculum for {{ $programName }}.
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
        @if ($replacesActiveVersion && ($impact['has_active_term_setup'] ?? false))
            <li>
                Active term classes and student sections recorded under the previous curriculum keep their setup, and continuing students can still enroll under their assigned plan.
            </li>
        @endif
        @if ($draftSubjects !== [])
            <li x-data="{ open: false }">
                <div class="flex flex-wrap items-center gap-2">
                    <span>It also makes {{ count($draftSubjects) }} draft {{ str('subject')->plural(count($draftSubjects)) }} in this plan active.</span>
                    <button
                        type="button"
                        class="fi-link text-xs font-medium"
                        x-on:click="open = ! open"
                        :aria-expanded="open.toString()"
                    >
                        <span x-text="open ? 'Hide {{ count($draftSubjects) === 1 ? 'subject' : 'subjects' }}' : 'Show {{ count($draftSubjects) === 1 ? 'subject' : 'subjects' }}'"></span>
                    </button>
                </div>
                <ul class="tala-activation-impact__list mt-1.5" x-show="open" x-cloak>
                    @foreach ($draftSubjects as $draftSubjectLabel)
                        <li>{{ $draftSubjectLabel }}</li>
                    @endforeach
                </ul>
            </li>
        @endif
    </ul>
</div>
