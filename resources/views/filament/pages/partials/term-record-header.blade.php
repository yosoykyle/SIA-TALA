<div class="tala-planning-heading">
    <x-filament-panels::header
        :heading="$this->getHeading()"
        :subheading="$this->getSubheading()"
        :breadcrumbs="$this->getBreadcrumbs()"
        :actions="$this->getCachedHeaderActions()"
        :actions-alignment="$this->getHeaderActionsAlignment()"
    />
    @if ($term)<p class="tala-planning-record-context">{{ collect([$term->typeLabel(), $term->academicYear?->label])->filter()->unique()->implode(' · ') }}</p>@endif
</div>
