<div class="tala-guide-record-header">
    <header class="tala-guide-masthead tala-guide-record-masthead" aria-label="Term context">
        <div class="tala-guide-school">
            <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="" width="40" height="40">
            <span><strong>Servitech Institute Asia</strong><small>Term planning</small></span>
        </div>
        <div class="tala-guide-record-term">
            @if ($term)<strong>{{ $term->academicYear?->label }} · {{ \App\Models\Term::typeOptions()[$term->type] ?? $term->type }}</strong><span>{{ $term->label }} · {{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</span>
            @else<strong>Academic Terms</strong><span>Select a Term in the summary to prepare its records.</span>@endif
        </div>
        <x-filament::dropdown placement="bottom-end">
            <x-slot name="trigger"><x-filament::button color="gray" icon="heroicon-o-chevron-down" icon-position="after">Term options</x-filament::button></x-slot>
            <x-filament::dropdown.list>
                @if ($term)<x-filament::dropdown.list.item tag="a" icon="heroicon-o-clock" :href="\App\Filament\Resources\Terms\TermResource::getUrl('view', ['record' => $term, 'termId' => $term->id])">Term details & history</x-filament::dropdown.list.item>@endif
                @if (\App\Filament\Resources\Terms\TermResource::canViewAny())<x-filament::dropdown.list.item tag="a" icon="heroicon-o-arrow-path" :href="\App\Filament\Resources\Terms\TermResource::getUrl('index')">Choose another Term</x-filament::dropdown.list.item>@endif
                @if (\App\Filament\Resources\Terms\TermResource::canCreate())<x-filament::dropdown.list.item tag="a" icon="heroicon-o-plus" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['termId' => $term?->id])">Create Term</x-filament::dropdown.list.item>@endif
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </header>
    <x-tala-term-destinations :navigation="$navigation" />
    <x-filament-panels::header
        :heading="$this->getHeading()"
        :subheading="$this->getSubheading()"
        :breadcrumbs="$this->getBreadcrumbs()"
        :actions="$this->getCachedHeaderActions()"
        :actions-alignment="$this->getHeaderActionsAlignment()"
    />
</div>
