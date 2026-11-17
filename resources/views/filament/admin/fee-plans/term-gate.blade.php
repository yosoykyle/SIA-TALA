@php
    $filters = $this->getTableFiltersForm();
    $activeTerm = $this->activeFeePlanTerm();
    $draftCount = $this->draftFeePlanCount();
    $draftAttentionItems = $draftCount > 0
        ? [[
            'label' => 'Draft fee plans',
            'count' => $draftCount,
            'wireClick' => 'showDraftFeePlans',
            'icon' => 'heroicon-o-document-text',
        ]]
        : [];
@endphp
<x-servitech-term-gate
    wire:key="fee-plans-term-gate-view"
    heading="Choose a term or program"
    description="Select a term, program, or fee plan to view."
    icon="heroicon-o-calculator"
    heading-id="fee-plans-term-gate-heading"
    :attention="$draftAttentionItems"
    attention-label="Draft fee plans needing attention"
    :split="true"
>
    <div class="tala-term-gate__search">
        <x-filament-tables::search-field label="Search fee plans" :label-hidden="false" placeholder="Program or reference" />
    </div>
    <div>{{ $filters->getComponent('term') }}</div>
    <div>{{ $filters->getComponent('program') }}</div>

    <x-slot:actions>
        @if ($activeTerm !== null)
            <x-filament::button type="button" color="primary" icon="heroicon-m-calendar-days" wire:click="selectFeePlanTerm({{ $activeTerm->id }})">
                Open {{ $activeTerm->label }}
            </x-filament::button>
        @endif
        <x-filament::button type="button" :color="$activeTerm !== null ? 'gray' : 'primary'" icon="heroicon-o-list-bullet" wire:click="showAllTerms">
            Show all terms
        </x-filament::button>
    </x-slot:actions>
</x-servitech-term-gate>
