@props(['heading', 'description', 'scope' => null, 'office' => null, 'icon' => 'heroicon-o-information-circle', 'attention' => false])
<section {{ $attributes->class(['servitech-task-state', 'servitech-task-state--attention' => $attention]) }}>
    <span class="servitech-task-state__icon"><x-filament::icon :icon="$icon" aria-hidden="true" /></span>
    <div class="servitech-task-state__body">
        <h2>{{ $heading }}</h2>
        @if ($scope)<p class="servitech-task-state__scope">{{ $scope }}</p>@endif
        <p>{{ $description }}</p>
        @if ($office)<p class="servitech-task-state__office">Responsible office: <strong>{{ $office }}</strong></p>@endif
        @if ($slot->isNotEmpty())<div class="servitech-task-state__actions">{{ $slot }}</div>@endif
    </div>
</section>
