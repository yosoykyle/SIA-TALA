<div>
    <x-filament-panels::header
        :heading="$this->getHeading()"
        :subheading="$this->getSubheading()"
        :breadcrumbs="$this->getBreadcrumbs()"
        :actions="$this->getCachedHeaderActions()"
        :actions-alignment="$this->getHeaderActionsAlignment()"
    />

    <div class="mt-4">
        <x-tala-kpi-strip :items="$metrics" aria-label="Content summary" />
    </div>
</div>
