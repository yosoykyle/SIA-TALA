<x-record-collection-controls :heading="['accounts' => 'All student accounts', 'payment_proof' => 'Review receipts', 'awaiting_payment' => 'Waiting for payment', 'assessment_required' => 'Assessment reviews', 'student_changes' => 'Student status changes'][$this->activeTab] ?? 'Student accounts'">
    <x-slot:filters>
        <x-record-collection-filters :table="$this->getTable()" :form="$filters" wire:key="accounting-collection-filters-{{ $this->activeTab }}" />
    </x-slot:filters>
    <x-slot:search><x-filament-tables::search-field label="Name or student ID" placeholder="Name or ID" /></x-slot:search>
    @if ($selectedTerm !== null || $selectedProgram !== null)
        <div class="tala-record-collection-active-filters" aria-label="Account term and program scope">
            @if ($selectedTerm !== null)
                <x-filament::badge color="gray">{{ $selectedTerm->label }}@if ($selectedTerm->academicYear?->label) · {{ $selectedTerm->academicYear->label }}@endif</x-filament::badge>
            @endif
            @if ($selectedProgram !== null)<x-filament::badge color="gray">{{ $selectedProgram->name }}</x-filament::badge>@endif
        </div>
    @endif
    @if ($urgentNotice !== null)
        <div class="tala-record-collection-notice" role="status">
            <span>{{ $urgentNotice }}</span>
            @if ($this->activeTab === 'accounts')
                <x-filament::button type="button" color="gray" size="xs" wire:click="showAllAccountingTerms">Show all terms</x-filament::button>
            @else
                <x-filament::button type="button" color="gray" size="xs" wire:click="clearAccountingTermFilter">Show all terms</x-filament::button>
            @endif
        </div>
    @endif
    @if ($this->hasTableSearch())
        <x-filament::link tag="button" type="button" wire:click="resetTableSearch">Clear search</x-filament::link>
    @endif
</x-record-collection-controls>
