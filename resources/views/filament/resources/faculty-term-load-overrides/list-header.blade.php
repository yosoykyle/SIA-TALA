@include('filament.pages.partials.term-record-header')

@if ($term)
    <div class="tala-load-exceptions-default" aria-label="Term teaching-load default">
        <p>
            <strong>Term default:</strong>
            @if ($term->default_max_units !== null)
                {{ rtrim(rtrim(number_format((float) $term->default_max_units, 2, '.', ''), '0'), '.') }} units per teacher
            @else
                Not recorded
            @endif
        </p>

        @if (\App\Filament\Resources\Terms\TermResource::canEdit($term))
            <x-filament::button
                tag="a"
                color="gray"
                icon="heroicon-o-pencil-square"
                :href="\App\Filament\Resources\Terms\TermResource::getUrl('edit', ['record' => $term, 'termId' => $term->id, 'viewTab' => 'resources', 'resourceView' => 'faculty'])"
            >
                Edit Term default
            </x-filament::button>
        @endif
    </div>
@endif
