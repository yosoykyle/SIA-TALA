<section class="tala-planning-context" aria-label="Term context">
    @if ($term)
        <div class="tala-term-picker">{{ $this->termSelection }}</div>
        <div class="tala-term-context-links">@if ($readOnly)<span class="text-sm text-gray-600 dark:text-gray-300">Term evidence overview · Read-only oversight · Registrar owns these records</span><x-filament::link :href="\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term->id])">Academic Oversight</x-filament::link>@endif
        <x-filament::link :href="\App\Filament\Resources\Terms\TermResource::getUrl('view', ['record' => $term, 'termId' => $term->id, 'viewTab' => $viewTab, ...(isset($resourceView) ? ['resourceView' => $resourceView] : [])])">Term details & history</x-filament::link></div>
    @else
        <div class="tala-planning-picker">{{ $this->termSelection }}</div>
    @endif
</section>
<x-tala-navigation label="Term planning tasks">
    @foreach ($this->getWorkspaceNavigation() as $item)
        <x-filament::tabs.item tag="a" :href="$item->getUrl()" :active="$item->isActive()">{{ $item->getLabel() }}</x-filament::tabs.item>
    @endforeach
</x-tala-navigation>
