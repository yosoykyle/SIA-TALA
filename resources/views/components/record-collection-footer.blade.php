@props(['paginator' => null, 'pageState' => [], 'method' => 'gotoPage', 'label' => 'records', 'pageOptions' => []])
@php
    $from = $paginator?->firstItem() ?? ($pageState['from'] ?? 0);
    $to = $paginator?->lastItem() ?? ($pageState['to'] ?? 0);
    $total = $paginator?->total() ?? ($pageState['total'] ?? 0);
    $page = $paginator?->currentPage() ?? ($pageState['page'] ?? 1);
    $lastPage = $paginator?->lastPage() ?? ($pageState['lastPage'] ?? 1);
    $pageNameArgument = $paginator && $method === 'gotoPage' ? ', '.json_encode($paginator->getPageName(), JSON_THROW_ON_ERROR) : '';
@endphp
<footer {{ $attributes->class(['tala-profile-collection-footer']) }}>
    <p role="status">{{ $from }}–{{ $to }} of {{ $total }} {{ $total === 1 ? \Illuminate\Support\Str::singular($label) : $label }}</p>
    <div class="tala-collection-page-controls">
    @if ($pageOptions !== [] && $total > min($pageOptions))
        <label>
            <x-filament::input.wrapper prefix="Per page">
                <x-filament::input.select wire:model.live="tableRecordsPerPage">
                    @foreach ($pageOptions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <span class="fi-sr-only">{{ ucfirst($label) }} per page</span>
        </label>
    @endif
    @if ($lastPage > 1)
        <nav aria-label="{{ ucfirst($label) }} pages"><x-filament::button icon="heroicon-m-chevron-left" label-sr-only color="gray" class="tala-collection-page-button" :disabled="$page <= 1" :wire:click="$method.'('.max(1, $page - 1).$pageNameArgument.')'">Previous page</x-filament::button><span>Page {{ $page }} of {{ $lastPage }}</span><x-filament::button icon="heroicon-m-chevron-right" label-sr-only color="gray" class="tala-collection-page-button" :disabled="$page >= $lastPage" :wire:click="$method.'('.min($lastPage, $page + 1).$pageNameArgument.')'">Next page</x-filament::button></nav>
    @endif
    </div>
</footer>
