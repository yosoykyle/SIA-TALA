@props(['heading', 'subheading', 'actions' => [], 'actionsAlignment' => null])

<div class="tala-home-greeting">
    @include('filament.components.learner-journey-art')

    <x-filament-panels::header
        :actions="$actions"
        :actions-alignment="$actionsAlignment"
        :breadcrumbs="[]"
        :heading="$heading"
        :subheading="$subheading"
    />
</div>
