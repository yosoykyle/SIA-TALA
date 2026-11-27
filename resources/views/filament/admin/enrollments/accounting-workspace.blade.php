@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $gated = $this->accountingTermGateActive();
    $scope = $this->accountingScopeContext();
    $filters = $this->getTableFiltersForm();
    $records = $gated ? collect() : $this->getTableRecords();
    $selected = $gated ? null : $this->selectedAccountingRecord();
    $activeTerm = $scope['activeTerm'];
    $selectedTerm = $scope['selectedTerm'];
    $workCounts = $scope['workCounts'];
@endphp
<div class="accounting-collection">
    @if ($gated)
        @php
            $attentionItems = collect([
                ['tab' => 'payment_proof', 'key' => 'proof', 'label' => 'Review receipts', 'icon' => 'heroicon-o-document-magnifying-glass'],
                ['tab' => 'awaiting_payment', 'key' => 'payment', 'label' => 'Waiting for payment', 'icon' => 'heroicon-o-banknotes'],
                ['tab' => 'student_changes', 'key' => 'student_changes', 'label' => 'Student status changes', 'icon' => 'heroicon-o-clipboard-document-check'],
                ['tab' => 'assessment_required', 'key' => 'assessment', 'label' => 'Assessment reviews', 'icon' => 'heroicon-o-document-currency-dollar'],
            ])
                ->filter(fn (array $item): bool => ($workCounts[$item['key']] ?? 0) > 0)
                ->map(fn (array $item): array => [
                    'label' => $item['label'],
                    'count' => $workCounts[$item['key']],
                    'href' => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $item['tab']]),
                    'icon' => $item['icon'],
                ])
                ->values()
                ->all();
        @endphp
        <x-servitech-term-gate
            wire:key="accounting-term-gate-{{ $this->activeTab }}"
            heading="Choose a term or student"
            description="Select a term or search by name or student number."
            icon="heroicon-o-calendar-days"
            heading-id="accounting-term-gate-heading"
            :attention="$attentionItems"
            attention-label="Pending Accounting queues"
            :split="true"
        >
            <div class="tala-term-gate__search">
                <x-filament-tables::search-field label="Search student name or number" :label-hidden="false" placeholder="Name or student number" />
            </div>
            <div>{{ $filters->getComponent('term') }}</div>
            <div>{{ $filters->getComponent('program') }}</div>

            <x-slot:actions>
                @if ($activeTerm !== null)
                    <x-filament::button type="button" color="primary" icon="heroicon-m-calendar-days" wire:click="selectAccountingTerm({{ $activeTerm->id }})">
                        Open {{ $activeTerm->label }}
                    </x-filament::button>
                @endif
                <x-filament::button type="button" :color="$activeTerm !== null ? 'gray' : 'primary'" icon="heroicon-o-list-bullet" wire:click="showAllAccountingTerms">
                    Show all terms
                </x-filament::button>
            </x-slot:actions>
        </x-servitech-term-gate>
    @else
        @php
            $selectedProgram = $scope['selectedProgram'];
            $urgentNotice = null;
            if ($selectedTerm !== null && $this->activeTab === 'accounts' && $scope['otherTermPendingCount'] > 0) {
                $urgentNotice = $scope['otherTermPendingCount'].' pending outside this view.';
            } elseif ($selectedTerm !== null && $this->activeTab !== 'accounts' && $scope['hiddenCurrentQueueCount'] > 0) {
                $urgentNotice = $scope['hiddenCurrentQueueCount'].' more outside this view.';
            }
        @endphp



        @if ($records->isNotEmpty())
            <x-record-workspace wire:key="accounting-populated-workspace-{{ $this->activeTab }}" collection-label="student accounts" selection-parameter="account" selection-actions="selectAccountingRecord" loading-label="Loading student account" scroll-mode="document" class="accounting-selected-workspace">
                <x-slot:browser aria-label="Student accounts">
                    @include('filament.admin.enrollments.accounting-collection-controls')
                    <ul class="tala-record-list">
                        @foreach ($records as $case)
                            @php($summary = $this->accountingRecordSummary($case))
                            <li wire:key="{{ $this->getId() }}.table.records.{{ $case->getKey() }}">
                                <x-record-option :selected="$selected?->id === $case->id" wire:click="selectAccountingRecord({{ $case->id }})" aria-controls="accounting-account-inspector">
                                    <strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }}</strong>
                                    <small>{{ \App\Actions\Finance\AccountingDisplay::learnerIdentifier($case) }} · {{ $case->term?->label ?? 'Term unavailable' }}</small>
                                    <x-slot:meta><x-filament::icon icon="heroicon-m-chevron-right" class="accounting-option-chevron" aria-hidden="true" /></x-slot:meta>
                                    <x-slot:context><span class="accounting-option-context"><x-filament::icon :icon="$summary['icon']" aria-hidden="true" />{{ $summary['label'] }}</span></x-slot:context>
                                </x-record-option>
                            </li>
                        @endforeach
                    </ul>
                </x-slot:browser>
                <x-slot:collectionFooter>
                    <x-record-collection-footer :paginator="$records" label="student accounts" :page-options="$this->getTable()->getPaginationPageOptions()" />
                </x-slot:collectionFooter>
                <x-slot:inspector id="accounting-account-inspector" aria-label="Selected student account">
                    @if ($selected)
                        @livewire(\App\Filament\Resources\Enrollments\Pages\AccountingAccountInspector::class, [
                            'record' => $selected->id,
                            'enrollmentView' => $this->accountingInspectorView(),
                            'returnQueue' => $this->activeTab,
                            'returnSearch' => $this->getTableSearch(),
                            'returnFilters' => $this->tableFilters ?? [],
                            'paymentProofId' => request()->integer('proof') ?: null,
                        ], key('account-inspector-'.$selected->id.'-'.$this->activeTab.'-'.$this->accountingSection))
                    @else
                        <x-servitech-empty-state heading="Select a student account" description="The previous account is outside these results. Choose a student from the current page." icon="heroicon-o-users" />
                    @endif
                </x-slot:inspector>
            </x-record-workspace>
        @else
            <div wire:key="accounting-empty-workspace-{{ $this->activeTab }}" class="servitech-empty-collection">
                @include('filament.admin.enrollments.accounting-collection-controls')
                <x-servitech-empty-state :illustrated="! $this->hasTableSearch() && ! $this->getTable()->isFiltered()" :heading="$this->getTable()->getEmptyStateHeading()" :description="$this->getTable()->getEmptyStateDescription()">
                    @if ($this->hasTableSearch() || $this->getTable()->isFiltered())
                        <x-filament::button wire:click="clearAccountingFilters" color="gray">{{ $this->hasTableSearch() && ! $this->getTable()->isFiltered() ? 'Clear search' : 'Clear filters' }}</x-filament::button>
                        @if ($this->activeTab === 'accounts' && $selectedTerm !== null)
                            <x-filament::button type="button" wire:click="showAllAccountingTerms" color="gray">Show all terms</x-filament::button>
                        @endif
                    @elseif ($this->activeTab === 'accounts')
                        <x-filament::button tag="a" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index')" color="gray">Back to Overview</x-filament::button>
                    @else
                        <x-filament::button tag="a" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => 'accounts'])" color="gray">All student accounts</x-filament::button>
                    @endif
                </x-servitech-empty-state>
            </div>
        @endif
    @endif
</div>
