@php
    $outsideDraftCount = $this->outsideDraftFeePlanCount();
    $scope = $this->feePlanScopeDetails();
@endphp
<x-record-scope-bar
    class="accounting-fee-plan-controls"
    label="Draft fee plans outside this view"
    :icon="$scope['icon']"
    :scope-title="$scope['title']"
    :urgent-notice="$outsideDraftCount > 0 ? ($outsideDraftCount === 1 ? '1 draft outside this view.' : $outsideDraftCount.' drafts outside this view.') : null"
>
    @if ($outsideDraftCount > 0)
        <x-slot:notice>
            <x-filament::button color="gray" size="xs" wire:click="showDraftFeePlans">
                Review drafts
            </x-filament::button>
        </x-slot:notice>
    @endif
</x-record-scope-bar>
