<div role="status">
    <x-servitech-empty-state :heading="$heading ?? $table->getEmptyStateHeading()" :description="$description ?? $table->getEmptyStateDescription()" illustrated>
        @foreach ($table->getEmptyStateActions() as $action)
            @if ($action->isVisible()){{ $action }}@endif
        @endforeach
    </x-servitech-empty-state>
</div>
