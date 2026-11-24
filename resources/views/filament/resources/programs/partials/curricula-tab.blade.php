<div class="tala-program-panel__header">
    <div class="tala-program-panel__heading">
        <h2 class="tala-program-panel__title">Curricula</h2>
        <p class="tala-program-panel__description">
            Manage batch study plans for {{ $program->code }}. Active curricula can accept additional batches without duplicating subjects.
        </p>
    </div>

    @if ($canCreateCurriculum && $curriculaRows !== [])
        <div class="shrink-0">
            @if (! $program->is_active)
                <x-filament::button
                    color="primary"
                    size="sm"
                    icon="heroicon-m-plus"
                    wire:click="mountAction('activateBeforeCreateCurriculum', { mode: 'blank' })"
                >
                    Add new curriculum
                </x-filament::button>
            @else
                <x-filament::button
                    color="primary"
                    size="sm"
                    icon="heroicon-m-plus"
                    wire:click="mountAction('createCurriculum', { mode: 'blank' })"
                >
                    Add new curriculum
                </x-filament::button>
            @endif
        </div>
    @endif
</div>

@if ($curriculaRows === [])
    <x-servitech-empty-state
        :illustrated="true"
        icon="heroicon-o-clipboard-document-list"
        heading="No curricula yet"
        :description="$canCreateCurriculum
            ? 'Create the first batch curriculum for ' . $program->code . ' so incoming students can apply and enroll.'
            : 'No curricula have been recorded for ' . $program->code . ' yet.'"
        heading-tag="h3"
    >
        @if ($canCreateCurriculum)
            @if (! $program->is_active)
                <x-filament::button
                    color="primary"
                    size="sm"
                    icon="heroicon-m-plus"
                    wire:click="mountAction('activateBeforeCreateCurriculum', { mode: 'blank' })"
                >
                    Add new curriculum
                </x-filament::button>
            @else
                <x-filament::button
                    color="primary"
                    size="sm"
                    icon="heroicon-m-plus"
                    wire:click="mountAction('createCurriculum', { mode: 'blank' })"
                >
                    Add new curriculum
                </x-filament::button>
            @endif
        @endif
    </x-servitech-empty-state>
@else
    <x-data-table label="Curricula for {{ $program->code }}" class="tala-program-panel__table">
        <thead>
            <tr>
                <th scope="col">Curriculum</th>
                <th scope="col">Batches</th>
                <th scope="col">Subjects &amp; units</th>
                <th scope="col" class="tala-data-table-number">Students</th>
                <th scope="col" class="tala-program-table__status-col">Status</th>
                <th scope="col" class="text-end tala-program-table__actions-col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($curriculaRows as $row)
                @php
                    $isDraft = $row['state'] === \App\Models\CurriculumVersion::StateDraft;
                    $isActive = $row['state'] === \App\Models\CurriculumVersion::StateActive;
                    $activeSecondaryActions = [];

                    if ($isActive && $canManage) {
                        $activeSecondaryActions[] = [
                            'label' => 'Add batch',
                            'icon' => 'heroicon-m-plus',
                            'action' => $program->is_active ? 'addBatchToCurriculum' : 'activateBeforeAddBatch',
                            'args' => "{ curriculumId: {$row['id']} }",
                        ];

                        if ($canCreateCurriculum) {
                            $activeSecondaryActions[] = [
                                'label' => 'Copy',
                                'icon' => 'heroicon-m-document-duplicate',
                                'action' => $program->is_active ? 'createCurriculum' : 'activateBeforeCreateCurriculum',
                                'args' => "{ mode: 'copy', sourceCurriculumId: {$row['id']} }",
                            ];
                        }

                        $activeSecondaryActions[] = [
                            'label' => 'Retire',
                            'icon' => 'heroicon-m-archive-box',
                            'action' => 'retireCurriculum',
                            'args' => "{ curriculumId: {$row['id']} }",
                        ];
                    }
                @endphp
                <tr wire:key="curriculum-row-{{ $row['id'] }}">
                    <td>
                        <div class="tala-program-table__primary">
                            {{ $row['batch_label'] }}
                        </div>
                        @if ($row['copied_from_label'])
                            <div class="tala-data-table-secondary">
                                Copied from {{ $row['copied_from_label'] }}
                            </div>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($row['batches_count'] === 0)
                            <span class="tala-program-table__muted">Not assigned</span>
                        @else
                            <div class="tala-program-table__primary tabular-nums">
                                {{ $row['batches_count'] }} {{ \Illuminate\Support\Str::plural('batch', $row['batches_count']) }}
                            </div>
                            @if ($row['batches_range'])
                                <div class="tala-data-table-secondary tabular-nums">
                                    {{ $row['batches_range'] }}
                                </div>
                            @endif
                        @endif
                    </td>

                    <td class="whitespace-nowrap tala-program-table__muted tabular-nums">
                        <span class="tala-program-table__primary">{{ $row['subjects_count'] }}</span>
                        {{ \Illuminate\Support\Str::plural('subject', $row['subjects_count']) }}
                        <span aria-hidden="true">·</span>
                        <span class="tala-program-table__primary">{{ $row['units_formatted'] }}</span>
                        units
                    </td>

                    <td class="tala-data-table-number tala-program-table__primary">
                        {{ number_format($row['students_count']) }}
                    </td>

                    <td class="whitespace-nowrap tala-program-table__status-col">
                        <x-filament::badge :color="$row['status_color']" size="sm">
                            {{ $row['status_label'] }}
                        </x-filament::badge>
                    </td>

                    <td class="whitespace-nowrap text-end tala-program-table__actions-col">
                        <div class="tala-program-table__actions">
                            @if ($isDraft)
                                @if ($row['continue_requires_activation'])
                                    <x-filament::button
                                        color="primary"
                                        size="sm"
                                        icon="heroicon-m-arrow-right"
                                        icon-position="after"
                                        wire:click="mountAction('activateAndContinueDraft', { curriculumId: {{ $row['id'] }} })"
                                    >
                                        Continue
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        tag="a"
                                        :href="$row['review_url']"
                                        color="primary"
                                        size="sm"
                                        icon="heroicon-m-arrow-right"
                                        icon-position="after"
                                    >
                                        Continue
                                    </x-filament::button>
                                @endif

                                @if ($row['can_delete_draft'])
                                    <x-filament::button
                                        color="danger"
                                        size="sm"
                                        outlined
                                        icon="heroicon-m-trash"
                                        wire:click="mountAction('deleteDraftCurriculum', { curriculumId: {{ $row['id'] }} })"
                                    >
                                        Delete
                                    </x-filament::button>
                                @endif
                            @elseif ($isActive)
                                <x-filament::button
                                    tag="a"
                                    :href="$row['review_url']"
                                    color="gray"
                                    size="sm"
                                >
                                    Open
                                </x-filament::button>

                                @if (count($activeSecondaryActions) === 1)
                                    @php $singleAction = $activeSecondaryActions[0]; @endphp
                                    <x-filament::button
                                        color="gray"
                                        size="sm"
                                        :icon="$singleAction['icon']"
                                        wire:click="mountAction('{{ $singleAction['action'] }}', {{ $singleAction['args'] }})"
                                    >
                                        {{ $singleAction['label'] }}
                                    </x-filament::button>
                                @elseif (count($activeSecondaryActions) >= 2)
                                    <x-filament::dropdown
                                        placement="bottom-end"
                                        class="tala-program-table__dropdown"
                                        wire:key="curriculum-actions-{{ $row['id'] }}"
                                    >
                                        <x-slot:trigger>
                                            <x-filament::icon-button
                                                icon="heroicon-m-ellipsis-horizontal"
                                                color="gray"
                                                size="sm"
                                                label="More actions"
                                            />
                                        </x-slot:trigger>

                                        <x-filament::dropdown.list>
                                            @foreach ($activeSecondaryActions as $secondaryAction)
                                                <x-filament::dropdown.list.item
                                                    :icon="$secondaryAction['icon']"
                                                    x-on:click="close"
                                                    wire:click="mountAction('{{ $secondaryAction['action'] }}', {{ $secondaryAction['args'] }})"
                                                >
                                                    {{ $secondaryAction['label'] }}
                                                </x-filament::dropdown.list.item>
                                            @endforeach
                                        </x-filament::dropdown.list>
                                    </x-filament::dropdown>
                                @endif
                            @else
                                <x-filament::button
                                    tag="a"
                                    :href="$row['review_url']"
                                    color="gray"
                                    size="sm"
                                >
                                    Open
                                </x-filament::button>

                                @if ($canCreateCurriculum)
                                    <x-filament::button
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-m-document-duplicate"
                                        wire:click="mountAction('{{ $program->is_active ? 'createCurriculum' : 'activateBeforeCreateCurriculum' }}', { mode: 'copy', sourceCurriculumId: {{ $row['id'] }} })"
                                    >
                                        Copy
                                    </x-filament::button>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-data-table>
@endif
