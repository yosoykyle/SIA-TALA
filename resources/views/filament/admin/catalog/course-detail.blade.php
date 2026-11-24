@php
    /** @var \App\Models\Course $course */
    /** @var \App\Models\CourseSpecification|null $specification */
    /** @var \Illuminate\Support\Collection<int, \App\Models\CourseSpecification> $revisions */
    $isSubjectWorkspace = method_exists($this, 'selectRevision');
    $stateColor = fn (?string $state): string => match ($state) {
        \App\Models\CourseSpecification::StateActive => 'success',
        \App\Models\CourseSpecification::StateDraft => 'warning',
        default => 'gray',
    };
    $formatUnits = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $components = $specification ? $specification->components->sortBy('sequence')->values() : collect();
    if ($specification) {
        $specification->loadMissing(['requirements.relatedCourse.specifications']);
    }
    $requirements = $specification ? $specification->requirements->sortBy('sequence')->values() : collect();

    $sessionLabels = \App\Filament\Resources\CourseSpecifications\Schemas\CourseSpecificationForm::weeklySessionOptions();
    $isExternallyArranged = $specification
        && $specification->scheduling_treatment === \App\Models\CourseSpecification::SchedulingExternallyArranged;

    $meetingRows = $components->map(function ($component) use ($formatUnits, $sessionLabels): array {
        $typeLabel = \App\Models\CourseComponent::typeOptions()[$component->component_type] ?? $component->component_type;
        $patternLabel = $sessionLabels[$component->meeting_pattern] ?? ($formatUnits($component->weekly_contact_hours).' hrs/wk');

        return [
            'type' => $typeLabel,
            'pattern' => $patternLabel,
            'hours' => $formatUnits($component->weekly_contact_hours),
        ];
    });

    $equipmentLabels = \App\Support\RoomEquipment::options();
    $roomTypes = $components
        ->pluck('room_type_default')
        ->filter()
        ->unique()
        ->map(fn (string $type): string => \App\Models\CourseComponent::roomTypeOptions()[$type] ?? $type)
        ->values();
    $requiredEquipment = $components
        ->flatMap(fn ($component): array => (array) ($component->required_room_feature_keys ?? []))
        ->filter()
        ->unique()
        ->map(fn (string $key): string => $equipmentLabels[$key] ?? \Illuminate\Support\Str::headline(strtolower($key)))
        ->values();

    $deliveryModeList = $specification && ! empty($specification->allowed_modalities)
        ? collect($specification->allowed_modalities)
            ->map(fn (string $mode): string => \App\Models\CourseSpecification::modalityOptions()[$mode] ?? $mode)
            ->values()
        : collect();

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
    $classification = $specification?->academic_classification;
    $isOrdinary = ! $specification || $classification === \App\Models\CourseSpecification::AcademicClassificationOrdinary;
    $isTesdaCompetency = $classification === 'TESDA_COMPETENCY';
    $classificationBadgeLabel = match (true) {
        ! $specification => null,
        method_exists(\App\Models\CourseSpecification::class, 'shortClassificationLabel') => \App\Models\CourseSpecification::shortClassificationLabel($classification),
        $classification === 'TESDA_COMPETENCY' => 'TESDA Competency',
        $classification === 'NON_CREDIT' => 'Non-credit / Bridging',
        $classification === \App\Models\CourseSpecification::AcademicClassificationPe => 'Physical Education (PE)',
        $classification === \App\Models\CourseSpecification::AcademicClassificationNstp => 'NSTP',
        default => $subjectTypeOptions[$classification] ?? $classification,
    };
    $isExcludedFromGwa = $specification
        ? (method_exists(\App\Models\CourseSpecification::class, 'isExcludedFromGwa')
            ? \App\Models\CourseSpecification::isExcludedFromGwa($classification)
            : in_array($classification, [
                \App\Models\CourseSpecification::AcademicClassificationPe,
                \App\Models\CourseSpecification::AcademicClassificationNstp,
                'NON_CREDIT',
            ], true))
        : false;
    $pendingDraftRevision = $revisions->firstWhere('state', \App\Models\CourseSpecification::StateDraft);
    $currentActiveId = $revisions
        ->where('state', \App\Models\CourseSpecification::StateActive)
        ->sortByDesc('id')
        ->first()?->id;
    $isDraftSpecification = $specification instanceof \App\Models\CourseSpecification
        && $specification->state === \App\Models\CourseSpecification::StateDraft;
    $draftDiff = ($isDraftSpecification && method_exists($this, 'draftChangesSummary'))
        ? $this->draftChangesSummary($specification)
        : ['has_active' => false, 'changes' => []];
    $canDiscardDraft = ($isDraftSpecification && method_exists($this, 'canDiscardDraft'))
        ? $this->canDiscardDraft($specification)
        : false;

    $usage ??= \App\Filament\Resources\Courses\Schemas\CourseInfolist::curriculumUsage($course);
    $usageTotalCount = (int) ($usage['totalCount'] ?? 0);
    $usageFilteredCount = (int) ($usage['filteredCount'] ?? 0);
    $usageRows = $usage['rows'] ?? collect();
    $usageProgramOptions = $usage['programOptions'] ?? [];
    $usageHasFilters = (bool) ($usage['hasFilters'] ?? false);
    $usageCurrentPage = (int) ($usage['currentPage'] ?? 1);
    $usageLastPage = (int) ($usage['lastPage'] ?? 1);
    $usageFrom = (int) ($usage['from'] ?? 0);
    $usageTo = (int) ($usage['to'] ?? 0);
    $usageStatusOptions = \App\Filament\Resources\Courses\Schemas\CourseInfolist::usageStatusOptions();
@endphp

<div class="tala-catalog-detail">
    @if ($pendingDraftRevision && $specification && $specification->id !== $pendingDraftRevision->id && $isSubjectWorkspace)
        <x-tala-access-alert variant="info" title="A draft update is being prepared">
            <p>{{ $specification->id === $currentActiveId ? 'You are viewing the current active details.' : 'You are viewing an earlier active version.' }}
                <button type="button" class="tala-workspace-text-link" wire:click="selectRevision({{ $pendingDraftRevision->id }})">Open the draft</button>
            </p>
        </x-tala-access-alert>
    @endif

    <div class="tala-catalog-workspace">
        <div class="grid gap-4 min-w-0">
            <section class="tala-catalog-panel tala-task-surface" aria-label="Subject details">
                @if (! $specification)
                    <x-servitech-empty-state
                        :illustrated="false"
                        icon="heroicon-o-document-text"
                        heading="No teaching details yet"
                        description="This subject has no teaching details yet. Add teaching details to use it in curricula and schedules."
                        heading-tag="h2"
                    />
                @else
                    <div class="tala-catalog-panel__header">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2>Subject overview</h2>
                            @if ((! $isOrdinary || $isTesdaCompetency) && filled($classificationBadgeLabel))
                                <x-filament::badge :color="$isTesdaCompetency ? 'info' : 'gray'" size="sm">{{ $classificationBadgeLabel }}</x-filament::badge>
                            @endif
                        </div>
                        <x-filament::badge :color="$stateColor($specification->state)">{{ \App\Models\CourseSpecification::stateOptions()[$specification->state] ?? $specification->state }}</x-filament::badge>
                    </div>

                    @if (filled($specification->description))
                        <p class="tala-subject-lead">{{ $specification->description }}</p>
                    @endif

                    <div class="tala-subject-specs-wrap">
                        <dl class="tala-subject-specs">
                            <div class="tala-subject-spec">
                                <dt class="tala-subject-spec__term">Units</dt>
                                <dd class="tala-subject-spec__value">
                                    <div class="tala-subject-spec__units">
                                        <span class="tala-subject-spec__figure">{{ $formatUnits($specification->credit_units) }}</span>
                                        <span class="tala-subject-spec__unit-label">{{ \Illuminate\Support\Str::plural('unit', (float) $specification->credit_units) }}</span>
                                    </div>
                                    <p class="tala-subject-spec__note">{{ $isExcludedFromGwa ? 'Excluded from GWA' : 'Included in GWA' }}</p>
                                </dd>
                            </div>

                            <div class="tala-subject-spec">
                                <dt class="tala-subject-spec__term">
                                    <x-filament::icon icon="heroicon-o-clock" class="tala-subject-spec__icon" />
                                    <span>Weekly meetings</span>
                                </dt>
                                <dd class="tala-subject-spec__value">
                                    @if ($isExternallyArranged)
                                        <span class="tala-subject-spec__primary">Arranged / flexible</span>
                                        <p class="tala-subject-spec__note">No fixed weekly room or time slot</p>
                                    @elseif ($meetingRows->isEmpty())
                                        <span class="tala-subject-spec__note">Not set yet</span>
                                    @elseif ($meetingRows->count() === 1)
                                        @php $singleMeeting = $meetingRows->first(); @endphp
                                        <span class="tala-subject-spec__primary">{{ $singleMeeting['type'] }}</span>
                                        <p class="tala-subject-spec__note">{{ $singleMeeting['pattern'] }}</p>
                                    @else
                                        <ul class="tala-subject-spec__list">
                                            @foreach ($meetingRows as $meeting)
                                                <li>
                                                    <span class="tala-subject-spec__primary">{{ $meeting['type'] }}</span>
                                                    <span class="tala-subject-spec__sep" aria-hidden="true">·</span>
                                                    <span class="tala-subject-spec__hours">{{ $meeting['hours'] }}h/wk</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($specification->same_faculty_default)
                                            <p class="tala-subject-spec__note">Same teacher for Lecture &amp; Lab</p>
                                        @endif
                                    @endif
                                </dd>
                            </div>

                            <div class="tala-subject-spec">
                                <dt class="tala-subject-spec__term">
                                    <x-filament::icon icon="heroicon-o-building-office-2" class="tala-subject-spec__icon" />
                                    <span>Room requirement</span>
                                </dt>
                                <dd class="tala-subject-spec__value">
                                    @if ($isExternallyArranged)
                                        <span class="tala-subject-spec__primary">Flexible (no fixed room)</span>
                                    @elseif ($components->isEmpty())
                                        <span class="tala-subject-spec__note">Not set yet</span>
                                    @else
                                        <span class="tala-subject-spec__primary">{{ $roomTypes->isNotEmpty() ? $roomTypes->implode(', ') : 'Any standard room' }}</span>
                                        @if ($requiredEquipment->isNotEmpty())
                                            <ul class="tala-spec-chips" aria-label="Required room equipment">
                                                @foreach ($requiredEquipment as $equipmentLabel)
                                                    <li class="tala-spec-chip tala-spec-chip--subtle">{{ $equipmentLabel }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    @endif
                                </dd>
                            </div>

                            <div class="tala-subject-spec">
                                <dt class="tala-subject-spec__term">
                                    <x-filament::icon icon="heroicon-o-academic-cap" class="tala-subject-spec__icon" />
                                    <span>Delivery modes</span>
                                </dt>
                                <dd class="tala-subject-spec__value">
                                    @if ($deliveryModeList->isNotEmpty())
                                        <ul class="tala-spec-chips" aria-label="Allowed delivery modes">
                                            @foreach ($deliveryModeList as $modeLabel)
                                                <li class="tala-spec-chip">{{ $modeLabel }}</li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <span class="tala-subject-spec__note">Not set yet</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>

                    @if ($isDraftSpecification && $draftDiff['has_active'])
                        <div @class(['tala-draft-diff-notice', 'has-changes' => $draftDiff['changes'] !== []]) role="status">
                            <x-filament::icon :icon="$draftDiff['changes'] !== [] ? 'heroicon-o-document-check' : 'heroicon-o-document-text'" />
                            @if ($draftDiff['changes'] !== [])
                                <p><strong>Changes from current active version:</strong> {{ implode(', ', $draftDiff['changes']) }}.</p>
                            @else
                                <p>No changes made yet compared to the current active version. {{ $canDiscardDraft ? 'Select Edit draft to make changes, or Discard draft.' : 'Select Edit draft to make changes.' }}</p>
                            @endif
                        </div>
                    @endif

                    @if ($canManage && $isSubjectWorkspace && $isDraftSpecification)
                        <div class="tala-catalog-panel__footer">
                            <div class="tala-catalog-panel__footer-start">
                                @if ($canDiscardDraft)
                                    {{ $this->discardDraftAction }}
                                @else
                                    <p class="tala-catalog-panel__footer-note">Remove this draft from curricula before discarding.</p>
                                @endif
                            </div>
                            <div class="tala-catalog-panel__footer-end">
                                {{ $this->editRevisionAction }}
                                {{ $this->activateRevisionAction }}
                            </div>
                        </div>
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
                                <div class="tala-catalog-req-group">
                                    <span class="tala-catalog-req-label">{{ $group['label'] }}</span>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($group['items'] as $item)
                                            <a href="{{ $item['url'] }}" class="tala-catalog-req-pill">
                                                <strong>{{ $item['code'] }}</strong>
                                                @if (filled($item['title']))
                                                    <span class="tala-catalog-req-sep" aria-hidden="true">·</span>
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
                            @php
                                $isCurrent = $specification && $rev->id === $specification->id;
                                $isLatestActive = $rev->id === $currentActiveId;
                                $revisionHeading = match (true) {
                                    $isLatestActive => 'Current',
                                    $rev->state === \App\Models\CourseSpecification::StateActive => 'Active (earlier)',
                                    $rev->state === \App\Models\CourseSpecification::StateDraft => 'Draft',
                                    default => \App\Models\CourseSpecification::stateOptions()[$rev->state] ?? $rev->state,
                                };
                            @endphp
                            <li @class(['tala-catalog-tile', 'is-current' => $isCurrent])>
                                <div class="tala-catalog-tile__body">
                                    <div class="tala-catalog-tile__title">
                                        <strong>{{ $revisionHeading }}</strong>
                                        @if ($isLatestActive)
                                            <x-filament::badge color="success" size="sm">Active</x-filament::badge>
                                        @endif
                                    </div>
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
            @if ($usageTotalCount > 0)
                <x-filament::badge color="gray" size="sm" class="tabular-nums">
                    @if ($usageHasFilters || $usageLastPage > 1)
                        @if ($usageFilteredCount > 0)
                            Showing {{ $usageFrom }}–{{ $usageTo }} of {{ $usageFilteredCount }} {{ \Illuminate\Support\Str::plural('curriculum', $usageFilteredCount) }}
                        @else
                            0 of {{ $usageTotalCount }} {{ \Illuminate\Support\Str::plural('curriculum', $usageTotalCount) }}
                        @endif
                    @else
                        {{ $usageTotalCount }} {{ \Illuminate\Support\Str::plural('curriculum', $usageTotalCount) }}
                    @endif
                </x-filament::badge>
            @endif
        </div>

        @if ($usageTotalCount === 0)
            <x-servitech-empty-state
                :illustrated="true"
                icon="heroicon-o-clipboard-document-list"
                heading="Not used in any curriculum yet"
                description="Not attached to any program curriculum yet. Assign this subject to a draft curriculum to schedule it for upcoming terms."
                heading-tag="h3"
            />
        @else
            @if (method_exists($this, 'resetUsageFilters'))
                <div class="tala-usage-toolbar" role="search" aria-label="Filter curricula using {{ $course->code }}">
                    <div class="tala-usage-toolbar__filters">
                        <label class="tala-usage-field">
                            <span class="tala-usage-field__label">Program</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="usageProgramFilter" aria-label="Program">
                                    <option value="">All</option>
                                    @foreach ($usageProgramOptions as $programId => $programCode)
                                        <option value="{{ $programId }}">{{ $programCode }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>

                        <label class="tala-usage-field">
                            <span class="tala-usage-field__label">Status</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model.live="usageStatusFilter" aria-label="Status">
                                    <option value="">All</option>
                                    @foreach ($usageStatusOptions as $statusValue => $statusLabel)
                                        <option value="{{ $statusValue }}">{{ $statusLabel }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>

                        @if ($usageHasFilters)
                            <div class="tala-usage-toolbar__reset">
                                <x-filament::button
                                    type="button"
                                    size="sm"
                                    color="gray"
                                    wire:click="resetUsageFilters"
                                >
                                    Clear filters
                                </x-filament::button>
                            </div>
                        @endif
                    </div>

                    <label class="tala-usage-field tala-usage-field--search">
                        <span class="tala-usage-field__label">Search curriculum</span>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input
                                type="search"
                                wire:model.live.debounce.300ms="usageSearch"
                                placeholder="Curriculum or program"
                                aria-label="Search curriculum"
                            />
                        </x-filament::input.wrapper>
                    </label>
                </div>
            @endif

            @if ($usageRows->isEmpty())
                <x-servitech-empty-state
                    :illustrated="false"
                    icon="heroicon-o-magnifying-glass"
                    heading="No matching curricula"
                    description="Try a different program, status filter, or curriculum search."
                    heading-tag="h3"
                >
                    @if ($usageHasFilters && method_exists($this, 'resetUsageFilters'))
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            wire:click="resetUsageFilters"
                        >
                            Clear filters
                        </x-filament::button>
                    @endif
                </x-servitech-empty-state>
            @else
                <x-data-table label="Curricula using {{ $course->code }}" class="tala-usage-table">
                    <thead>
                        <tr>
                            <th scope="col">Program</th>
                            <th scope="col">Curriculum</th>
                            <th scope="col">Year &amp; semester</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($usageRows as $item)
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
                                    default => 'Retired',
                                };
                                $cvStateColor = match ($curriculum->state) {
                                    \App\Models\CurriculumVersion::StateActive => 'success',
                                    \App\Models\CurriculumVersion::StateDraft => 'warning',
                                    default => 'gray',
                                };
                            @endphp
                            <tr wire:key="course-usage-curriculum-{{ $curriculum->id }}">
                                <td class="whitespace-nowrap">
                                    <a href="{{ $programUrl }}" class="inline-flex items-center hover:opacity-85">
                                        <x-filament::badge color="info">{{ $curriculum->program->code }}</x-filament::badge>
                                    </a>
                                </td>
                                <td class="tala-usage-table__primary">
                                    {{ $curriculum->name }}
                                </td>
                                <td class="tala-usage-table__muted">
                                    {{ $item['placements']->implode(' · ') }}
                                </td>
                                <td class="whitespace-nowrap">
                                    <x-filament::badge :color="$cvStateColor" size="sm">{{ $cvStateLabel }}</x-filament::badge>
                                </td>
                                <td class="text-end whitespace-nowrap">
                                    <x-filament::button
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        :href="$curriculumUrl"
                                    >
                                        Open curriculum
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-data-table>

                @if ($usageLastPage > 1 && method_exists($this, 'setUsagePage'))
                    <nav class="tala-usage-pagination" aria-label="Curricula pagination">
                        <p class="tala-usage-pagination__status">
                            Page {{ $usageCurrentPage }} of {{ $usageLastPage }}
                        </p>
                        <div class="tala-usage-pagination__actions">
                            <x-filament::button
                                type="button"
                                size="xs"
                                color="gray"
                                :disabled="$usageCurrentPage <= 1"
                                wire:click="setUsagePage({{ $usageCurrentPage - 1 }})"
                            >
                                Previous
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                size="xs"
                                color="gray"
                                :disabled="$usageCurrentPage >= $usageLastPage"
                                wire:click="setUsagePage({{ $usageCurrentPage + 1 }})"
                            >
                                Next
                            </x-filament::button>
                        </div>
                    </nav>
                @endif
            @endif
        @endif
    </section>
</div>
