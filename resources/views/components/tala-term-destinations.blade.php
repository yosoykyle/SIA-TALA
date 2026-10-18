@props(['navigation', 'states' => []])
@php
    $icons = ['Term summary' => 'heroicon-o-home', 'Calendar' => 'heroicon-o-calendar-days', 'Classes & sections' => 'heroicon-o-book-open', 'Faculty & rooms' => 'heroicon-o-user-group', 'Timetable' => 'heroicon-o-table-cells'];
@endphp
<nav class="tala-guide-stations" aria-label="Term planning tasks">
    @foreach ($navigation as $item)
        @php($stage = $states[$item->getLabel()] ?? [])
        <a href="{{ $item->getUrl() }}" @class(['tala-guide-station', 'is-current' => $item->isActive(), 'is-ready' => $stage['ready'] ?? false]) @if ($item->isActive()) aria-current="page" @endif>
            <span class="tala-guide-station-icon"><x-filament::icon :icon="($stage['ready'] ?? false) ? 'heroicon-o-check-circle' : ($icons[$item->getLabel()] ?? 'heroicon-o-document-text')" /></span>
            <span><strong>{{ $item->getLabel() }}</strong>@if (isset($stage['state']))<small>{{ $stage['state'] }}</small>@endif</span>
        </a>
    @endforeach
</nav>
<div class="tala-term-mobile-destination">
    <label for="term-planning-destination">Working on</label>
    <x-filament::input.wrapper><x-filament::input.select id="term-planning-destination" x-on:change="window.location.assign($event.target.value)">
        @foreach ($navigation as $item)<option value="{{ $item->getUrl() }}" @selected($item->isActive())>{{ $item->getLabel() }}{{ isset($states[$item->getLabel()]['state']) ? ' · '.$states[$item->getLabel()]['state'] : '' }}</option>@endforeach
    </x-filament::input.select></x-filament::input.wrapper>
</div>
