@php($records = $this->getTableRecords())
<div class="tala-inc-queue" wire:loading.attr="aria-busy">
    @include('filament.admin.grade-rosters.inc-workspace', ['records' => $records])
    <x-filament-actions::modals />
</div>
