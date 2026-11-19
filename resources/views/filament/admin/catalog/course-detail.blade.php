@php
    /** @var \App\Models\Course $course */
    /** @var \App\Models\CourseSpecification|null $specification */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CourseSpecification> $revisions */
    $isSubjectWorkspace = method_exists($this, 'selectRevision');
    $stateColor = fn (?string $state): string => match ($state) {
        \App\Models\CourseSpecification::StateActive => 'success',
        \App\Models\CourseSpecification::StateDraft => 'info',
        default => 'gray',
    };
    $formatUnits = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $components = $specification ? $specification->components->sortBy('sequence')->values() : collect();
    $requirements = $specification ? $specification->requirements->sortBy('sequence')->values() : collect();
    $weeklyHours = match (true) {
        ! $specification => 'Not set yet',
        $specification->scheduling_treatment === \App\Models\CourseSpecification::SchedulingExternallyArranged => 'No weekly classes (arranged outside the timetable)',
        $components->isEmpty() => 'Not set yet',
        default => $components->map(fn ($component): string => (\App\Models\CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type).' '.$formatUnits($component->weekly_contact_hours).' h')->implode(' + '),
    };
    $deliveryModes = $specification && ! empty($specification->allowed_modalities)
        ? collect($specification->allowed_modalities)->map(fn (string $mode): string => \App\Models\CourseSpecification::modalityOptions()[$mode] ?? $mode)->implode(', ')
        : 'Not set yet';
    $requirementGroups = collect(\App\Models\CourseRequirement::typeOptions())
        ->map(fn (string $label, string $type): array => ['label' => $label, 'codes' => $requirements->where('rule_type', $type)->map(fn ($requirement): ?string => $requirement->relatedCourse?->code)->filter()->implode(', ')])
        ->filter(fn (array $group): bool => $group['codes'] !== '');
    $isOrdinary = ! $specification || $specification->academic_classification === \App\Models\CourseSpecification::AcademicClassificationOrdinary;
    $pendingDraftRevision = $revisions->firstWhere('state', \App\Models\CourseSpecification::StateDraft);
@endphp

<div class="tala-catalog-detail">
    @if ($pendingDraftRevision && $specification && $specification->id !== $pendingDraftRevision->id && $isSubjectWorkspace)
        <x-tala-access-alert variant="info" title="A draft update is being prepared">
            <p>You are viewing the current active details.
                <button type="button" class="tala-workspace-text-link" wire:click="selectRevision({{ $pendingDraftRevision->id }})">Open the draft</button>
            </p>
        </x-tala-access-alert>
    @endif

    <div class="tala-catalog-workspace">
        <section class="tala-catalog-panel tala-task-surface" aria-label="Subject details">
            @if (! $specification)
                <p class="tala-catalog-meta">This subject has no details yet.</p>
            @else
                <div class="tala-catalog-panel__header">
                    <h2>Details</h2>
                    <x-filament::badge :color="$stateColor($specification->state)">{{ \App\Models\CourseSpecification::stateOptions()[$specification->state] ?? $specification->state }}</x-filament::badge>
                </div>
                <dl class="tala-catalog-kv-list">
                    <div><dt>Units</dt><dd>{{ $formatUnits($specification->credit_units) }}</dd></div>
                    <div><dt>Weekly hours</dt><dd>{{ $weeklyHours }}</dd></div>
                    <div><dt>How it can be taught</dt><dd>{{ $deliveryModes }}</dd></div>
                    @forelse ($requirementGroups as $group)
                        <div><dt>{{ $group['label'] }}</dt><dd>{{ $group['codes'] }}</dd></div>
                    @empty
                        <div><dt>Prerequisites</dt><dd>None</dd></div>
                    @endforelse
                    @unless ($isOrdinary)
                        <div><dt>Counts toward the average</dt><dd>{{ \App\Models\CourseSpecification::academicClassificationOptions()[$specification->academic_classification] ?? 'Not set' }}</dd></div>
                    @endunless
                    @if (filled($specification->description))
                        <div><dt>Description</dt><dd>{{ $specification->description }}</dd></div>
                    @endif
                </dl>
                @if ($canManage && $isSubjectWorkspace && $specification->state === \App\Models\CourseSpecification::StateDraft)
                    <div class="tala-record-actions">{{ $this->activateRevisionAction }}</div>
                @endif
            @endif
        </section>

        @if ($revisions->count() > 1)
            <aside class="tala-catalog-rail" aria-labelledby="subject-versions-heading">
                <section class="tala-catalog-panel tala-task-surface">
                    <div class="tala-catalog-panel__header">
                        <h2 id="subject-versions-heading">Change history</h2>
                    </div>
                    <ul class="tala-catalog-tile-list">
                        @foreach ($revisions as $rev)
                            @php($isCurrent = $specification && $rev->id === $specification->id)
                            <li @class(['tala-catalog-tile', 'is-current' => $isCurrent])>
                                <div class="tala-catalog-tile__body">
                                    <strong>{{ \App\Models\CourseSpecification::stateOptions()[$rev->state] ?? $rev->state }}</strong>
                                    <span>{{ $formatUnits($rev->credit_units) }} units · Updated {{ $rev->updated_at->format('M j, Y') }}</span>
                                </div>
                                <div class="tala-catalog-tile__actions">
                                    @if ($isCurrent)
                                        <span class="tala-catalog-meta">Shown</span>
                                    @elseif ($isSubjectWorkspace)
                                        <x-filament::button size="sm" color="gray" wire:click="selectRevision({{ $rev->id }})">View</x-filament::button>
                                    @else
                                        <x-filament::button tag="a" size="sm" color="gray" :href="\App\Filament\Resources\Courses\CourseResource::getUrl('view', array_filter(['record' => $course, 'revision' => $rev->id, 'oversightTermId' => $oversightTermId], fn ($value) => $value !== null))">View</x-filament::button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            </aside>
        @endif
    </div>
</div>
