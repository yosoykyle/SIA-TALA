@php
    $batchCount = count($assignedBatches);
@endphp

<section class="tala-task-surface tala-batches-panel" aria-labelledby="curriculum-assigned-batches-heading">
    <header class="tala-batches-panel__header">
        <div class="tala-batches-panel__heading">
            <h2 id="curriculum-assigned-batches-heading" class="tala-batches-panel__title">Assigned batches</h2>
            <x-filament::badge :color="$batchCount === 0 ? 'warning' : 'gray'" size="sm" class="tala-tabular">
                {{ $batchCount }} {{ \Illuminate\Support\Str::plural('batch', $batchCount) }}
            </x-filament::badge>
        </div>

        @if ($canAddBatch && $assignedBatches !== [])
            <x-filament::button
                type="button"
                size="sm"
                color="gray"
                icon="heroicon-o-plus"
                wire:click="mountAction('addBatch')"
            >
                Add batch
            </x-filament::button>
        @endif
    </header>

    @if ($assignedBatches !== [])
        <x-data-table label="Assigned batches" class="tala-batches-panel__table">
            <thead>
                <tr>
                    <th scope="col">Batch</th>
                    <th scope="col" class="tala-data-table-number tala-batches-table__students-col">Students</th>
                    <th scope="col" class="tala-batches-table__added-col">Date added</th>
                    <th scope="col" class="text-end whitespace-nowrap tala-batches-table__actions-col">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($assignedBatches as $batch)
                    <tr wire:key="assigned-batch-{{ $batch['id'] }}">
                        <td class="tala-batches-table__batch">{{ $batch['label'] }}</td>
                        <td class="tala-data-table-number tala-batches-table__students">
                            {{ number_format($batch['students_count']) }}
                        </td>
                        <td class="tala-batches-table__added tabular-nums whitespace-nowrap">{{ $batch['added_at'] }}</td>
                        <td class="text-end whitespace-nowrap tala-batches-table__actions-col">
                            @if ($batch['can_remove'])
                                <x-filament::button
                                    type="button"
                                    size="xs"
                                    color="danger"
                                    outlined
                                    wire:click="mountAction('removeBatch', { academicYearId: {{ $batch['academic_year_id'] }} })"
                                >
                                    Remove
                                </x-filament::button>
                            @elseif ($batch['in_use'] ?? false)
                                <x-filament::badge color="gray" size="sm">In use</x-filament::badge>
                            @else
                                <span class="tala-batches-table__muted">-</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-data-table>
    @else
        <div class="tala-batches-panel__empty-advisory p-4">
            <x-tala-access-alert variant="warning" title="No starting batch assigned yet">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="m-0">No starting batch assigned yet. This curriculum will not appear in Admissions until a batch is assigned.</p>
                    @if ($canAddBatch)
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="warning"
                            icon="heroicon-o-plus"
                            wire:click="mountAction('addBatch')"
                        >
                            Assign batch
                        </x-filament::button>
                    @endif
                </div>
            </x-tala-access-alert>
        </div>
    @endif
</section>
