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
    $parseYearNumber = function (mixed $yearLevel): int {
        if (is_int($yearLevel)) {
            return $yearLevel;
        }
        $normalized = strtolower(trim((string) $yearLevel));
        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            str_contains($normalized, 'sixth') => 6,
            str_contains($normalized, 'seventh') => 7,
            str_contains($normalized, 'eighth') => 8,
            default => 0,
        };
    };
    $ordinalYear = fn (int $year): string => match ($year) {
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        default => $year > 0 ? "{$year}th Year" : 'Year not set',
    };
    $components = $specification ? $specification->components->sortBy('sequence')->values() : collect();
    if ($specification) {
        $specification->loadMissing(['requirements.relatedCourse.specifications']);
    }
    $requirements = $specification ? $specification->requirements->sortBy('sequence')->values() : collect();

    $sessionLabels = \App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm::weeklySessionOptions();
    $weeklyMeetingsSummary = match (true) {
        ! $specification => 'Not set yet',
        $specification->scheduling_treatment === \App\Models\CourseSpecification::SchedulingExternallyArranged => 'Arranged / flexible',
        $components->isEmpty() => 'Not set yet',
        $components->count() === 1 => (function () use ($components, $formatUnits, $sessionLabels): string {
            $component = $components->first();
            $typeLabel = \App\Models\CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type;
            $pattern = $sessionLabels[$component->meeting_pattern] ?? ($formatUnits($component->weekly_contact_hours).'h/wk');

            return "{$typeLabel} · {$pattern}";
        })(),
        default => $components->map(fn ($component): string => (\App\Models\CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type).' '.$formatUnits($component->weekly_contact_hours).'h')->implode(' + '),
    };

    $equipmentLabels = \App\Support\RoomEquipment::options();
    $roomRequirementsSummary = match (true) {
        ! $specification => 'Not set yet',
        $specification->scheduling_treatment === \App\Models\CourseSpecification::SchedulingExternallyArranged => 'None (flexible)',
        $components->isEmpty() => 'Not set yet',
        default => (function () use ($components, $equipmentLabels): string {
            $roomTypes = $components
                ->pluck('room_type_default')
                ->filter()
                ->unique()
                ->map(fn (string $type): string => \App\Models\CourseComponent::roomTypeOptions()[$type] ?? $type)
                ->values();
            $equipment = $components
                ->flatMap(fn ($component): array => (array) ($component->required_room_feature_keys ?? []))
                ->filter()
                ->unique()
                ->map(fn (string $key): string => $equipmentLabels[$key] ?? \Illuminate\Support\Str::headline(strtolower($key)))
                ->values();

            $base = $roomTypes->isNotEmpty() ? $roomTypes->implode(', ') : 'Any standard room';

            return $equipment->isNotEmpty() ? $base.' · '.$equipment->implode(', ') : $base;
        })(),
    };

    $deliveryModes = $specification && ! empty($specification->allowed_modalities)
        ? collect($specification->allowed_modalities)->map(fn (string $mode): string => \App\Models\CourseSpecification::modalityOptions()[$mode] ?? $mode)->implode(', ')
        : 'Not set yet';

    $requirementGroups = collect(\App\Models\CourseRequirement::typeOptions())
        ->map(function (string $label, string $type) use ($requirements, $oversightTermId): array {
            $items = $requirements
                ->where('rule_type', $type)
                ->map(function ($requirement) use ($oversightTermId): ?array {
                    $related = $requirement->relatedCourse;
                    if (! $related instanceof \App\Models\Course) {
                        return null;
                    }
                    $relatedSpec = $related->specifications
                        ->where('state', \App\Models\CourseSpecification::StateActive)
                        ->sortByDesc('id')
                        ->first()
                        ?? $related->specifications->sortByDesc('id')->first();

                    return [
                        'code' => $related->code,
                        'title' => $relatedSpec?->title,
                        'url' => \App\Filament\Resources\Courses\CourseResource::getUrl('view', array_filter([
                            'record' => $related->id,
                            'oversightTermId' => $oversightTermId,
                        ], fn (mixed $value): bool => $value !== null)),
                    ];
                })
                ->filter()
                ->values();

            return ['label' => $label, 'items' => $items];
        })
        ->filter(fn (array $group): bool => $group['items']->isNotEmpty());

    $subjectTypeOptions = \App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm::subjectTypeOptions();
    $isOrdinary = ! $specification || $specification->academic_classification === \App\Models\CourseSpecification::AcademicClassificationOrdinary;
    $pendingDraftRevision = $revisions->firstWhere('state', \App\Models\CourseSpecification::StateDraft);

    $course->loadMissing(['specifications.curriculumEntries.curriculumVersion.program']);
    $termNames = [
        \App\Models\Term::TypeFirstSemester => 'First Semester',
        \App\Models\Term::TypeSecondSemester => 'Second Semester',
        \App\Models\Term::TypeSpecialTerm => 'Summer',
    ];
    $termSort = fn (string $termType): int => match ($termType) {
        \App\Models\Term::TypeFirstSemester => 1,
        \App\Models\Term::TypeSecondSemester => 2,
        \App\Models\Term::TypeSpecialTerm => 3,
        default => 4,
    };

    $curriculumPlacements = $course->specifications
        ->flatMap(fn (\App\Models\CourseSpecification $spec) => $spec->curriculumEntries)
        ->filter(fn (\App\Models\CurriculumEntry $entry): bool => $entry->curriculumVersion instanceof \App\Models\CurriculumVersion
            && $entry->curriculumVersion->program instanceof \App\Models\Program)
        ->groupBy('curriculum_version_id')
        ->map(function (\Illuminate\Support\Collection $entries) use ($parseYearNumber, $ordinalYear, $termNames, $termSort): array {
            /** @var \App\Models\CurriculumEntry $first */
            $first = $entries->first();
            $cv = $first->curriculumVersion;
            $placements = $entries
                ->sortBy(fn (\App\Models\CurriculumEntry $e): string => sprintf('%03d-%d', $parseYearNumber($e->year_level), $termSort((string) $e->term_type)))
                ->map(function (\App\Models\CurriculumEntry $e) use ($parseYearNumber, $ordinalYear, $termNames): string {
                    $yr = $parseYearNumber($e->year_level);
                    $yrText = $yr > 0 ? $ordinalYear($yr) : 'Year not set';
                    $termText = $termNames[$e->term_type] ?? ($e->term_label !== '' ? $e->term_label : (string) $e->term_type);

                    return "{$yrText} · {$termText}";
                })
                ->unique()
                ->values();

            return [
                'curriculum' => $cv,
                'placements' => $placements,
            ];
        })
        ->sortBy(function (array $row): string {
            $cv = $row['curriculum'];
            $stateOrder = $cv->state === \App\Models\CurriculumVersion::StateActive ? 0 : ($cv->state === \App\Models\CurriculumVersion::StateDraft ? 1 : 2);

            return sprintf('%d-%s-%s', $stateOrder, (string) $cv->program?->code, (string) $cv->name);
        })
        ->values();
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
        <div class="grid gap-4 min-w-0">
            <section class="tala-catalog-panel tala-task-surface" aria-label="Subject details">
                @if (! $specification)
                    <p class="tala-catalog-meta">This subject has no teaching details yet. Add teaching details to use it in curricula and schedules.</p>
                @else
                    <div class="tala-catalog-panel__header">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2>Subject overview</h2>
                            @unless ($isOrdinary)
                                <x-filament::badge color="gray" size="sm">{{ $subjectTypeOptions[$specification->academic_classification] ?? $specification->academic_classification }}</x-filament::badge>
                            @endunless
                        </div>
                        <x-filament::badge :color="$stateColor($specification->state)">{{ \App\Models\CourseSpecification::stateOptions()[$specification->state] ?? $specification->state }}</x-filament::badge>
                    </div>

                    <dl class="grid grid-cols-1 gap-3 rounded-lg border p-3.5 sm:grid-cols-2 lg:grid-cols-4" style="border-color: var(--tala-edge); background: var(--tala-surface-sunken); margin: 0;">
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide" style="color: var(--tala-muted);">Units</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums" style="color: var(--tala-text); margin-inline-start: 0;">{{ $formatUnits($specification->credit_units) }} {{ \Illuminate\Support\Str::plural('unit', (float) $specification->credit_units) }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide" style="color: var(--tala-muted);">Weekly meetings</dt>
                            <dd class="mt-1 text-sm font-medium" style="color: var(--tala-text); margin-inline-start: 0;">{{ $weeklyMeetingsSummary }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide" style="color: var(--tala-muted);">Room requirement</dt>
                            <dd class="mt-1 text-sm font-medium" style="color: var(--tala-text); margin-inline-start: 0;">{{ $roomRequirementsSummary }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wide" style="color: var(--tala-muted);">Delivery modes</dt>
                            <dd class="mt-1 text-sm font-medium" style="color: var(--tala-text); margin-inline-start: 0;">{{ $deliveryModes }}</dd>
                        </div>
                    </dl>

                    @if ($components->count() > 1 && $specification->same_faculty_default)
                        <p class="tala-catalog-meta">Same teacher is assigned to both Lecture and Lab meetings for each section.</p>
                    @endif

                    @if (filled($specification->description))
                        <div class="rounded-lg border px-3.5 py-2.5 text-sm" style="border-color: var(--tala-edge); background: var(--tala-surface); color: var(--tala-text);">
                            <span class="block text-xs font-semibold uppercase tracking-wide mb-1" style="color: var(--tala-muted);">Description</span>
                            <p style="margin: 0;">{{ $specification->description }}</p>
                        </div>
                    @endif

                    @if ($canManage && $isSubjectWorkspace && $specification->state === \App\Models\CourseSpecification::StateDraft)
                        <div class="tala-record-actions">{{ $this->activateRevisionAction }}</div>
                    @endif
                @endif
            </section>

            @if ($specification)
                <section class="tala-catalog-panel tala-task-surface" aria-labelledby="subject-requirements-heading">
                    <div class="tala-catalog-panel__header">
                        <h2 id="subject-requirements-heading">Prerequisites &amp; equivalents</h2>
                    </div>
                    @if ($requirementGroups->isEmpty())
                        <p class="tala-catalog-meta">No prerequisites, corequisites, or approved equivalents recorded.</p>
                    @else
                        <div class="grid gap-3">
                            @foreach ($requirementGroups as $group)
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--tala-muted); min-width: 8.5rem;">{{ $group['label'] }}</span>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($group['items'] as $item)
                                            <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm hover:underline" style="border-color: var(--tala-edge); background: var(--tala-surface-sunken); color: var(--tala-text);">
                                                <strong style="color: var(--tala-brand-foreground);">{{ $item['code'] }}</strong>
                                                @if (filled($item['title']))
                                                    <span style="color: var(--tala-muted);">·</span>
                                                    <span>{{ $item['title'] }}</span>
                                                @endif
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>

        @if ($revisions->count() > 1)
            <aside class="tala-catalog-rail" aria-labelledby="subject-versions-heading">
                <section class="tala-catalog-panel tala-task-surface">
                    <div class="tala-catalog-panel__header">
                        <h2 id="subject-versions-heading">Change history</h2>
                    </div>
                    <ul class="tala-catalog-tile-list">
                        @foreach ($revisions as $rev)
                            @php $isCurrent = $specification && $rev->id === $specification->id; @endphp
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

    <section class="tala-catalog-panel tala-task-surface" aria-labelledby="used-in-programs-curricula-heading">
        <div class="tala-catalog-panel__header">
            <h2 id="used-in-programs-curricula-heading">Used in programs &amp; curricula</h2>
        </div>
        @if ($curriculumPlacements->isEmpty())
            <p class="tala-catalog-meta">Not attached to any program curriculum yet.</p>
        @else
            <div class="overflow-x-auto rounded-lg border" style="border-color: var(--tala-edge);">
                <table class="w-full text-start text-sm" style="border-collapse: collapse;">
                    <thead>
                        <tr style="background: var(--tala-surface-sunken); border-bottom: 1px solid var(--tala-edge); color: var(--tala-muted);">
                            <th class="px-3.5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide">Program</th>
                            <th class="px-3.5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide">Curriculum</th>
                            <th class="px-3.5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide">Year &amp; semester</th>
                            <th class="px-3.5 py-2.5 text-start text-xs font-semibold uppercase tracking-wide">Status</th>
                            <th class="px-3.5 py-2.5 text-end text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($curriculumPlacements as $item)
                            @php
                                /** @var \App\Models\CurriculumVersion $curriculum */
                                $curriculum = $item['curriculum'];
                                $programUrl = \App\Filament\Resources\Programs\ProgramResource::getUrl('view', array_filter([
                                    'record' => $curriculum->program_id,
                                    'oversightTermId' => $oversightTermId,
                                ], fn (mixed $value): bool => $value !== null));
                                $curriculumUrl = \App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::getUrl('review', array_filter([
                                    'record' => $curriculum->id,
                                    'programId' => $curriculum->program_id,
                                    'oversightTermId' => $oversightTermId,
                                ], fn (mixed $value): bool => $value !== null));
                                $cvStateLabel = match ($curriculum->state) {
                                    \App\Models\CurriculumVersion::StateActive => 'Active',
                                    \App\Models\CurriculumVersion::StateDraft => 'Draft',
                                    default => 'Inactive',
                                };
                                $cvStateColor = match ($curriculum->state) {
                                    \App\Models\CurriculumVersion::StateActive => 'success',
                                    \App\Models\CurriculumVersion::StateDraft => 'info',
                                    default => 'gray',
                                };
                            @endphp
                            <tr style="border-top: 1px solid var(--tala-edge);">
                                <td class="px-3.5 py-2.5 align-middle whitespace-nowrap">
                                    <a href="{{ $programUrl }}" class="inline-flex items-center hover:opacity-85">
                                        <x-filament::badge color="info">{{ $curriculum->program->code }}</x-filament::badge>
                                    </a>
                                </td>
                                <td class="px-3.5 py-2.5 align-middle font-medium" style="color: var(--tala-text);">
                                    {{ $curriculum->name }}
                                </td>
                                <td class="px-3.5 py-2.5 align-middle" style="color: var(--tala-muted);">
                                    {{ $item['placements']->implode(' · ') }}
                                </td>
                                <td class="px-3.5 py-2.5 align-middle whitespace-nowrap">
                                    <x-filament::badge :color="$cvStateColor" size="sm">{{ $cvStateLabel }}</x-filament::badge>
                                </td>
                                <td class="px-3.5 py-2.5 align-middle text-end whitespace-nowrap">
                                    <x-filament::button
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-m-arrow-right"
                                        icon-position="after"
                                        :href="$curriculumUrl"
                                    >
                                        Open curriculum
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
