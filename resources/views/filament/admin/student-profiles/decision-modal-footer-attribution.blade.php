@props(['actorName' => 'Registrar'])

<div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 tala-modal-attribution select-none">
    <x-filament::icon icon="heroicon-o-user" class="w-4 h-4 text-gray-400 shrink-0" />
    <span>Recorded by {{ $actorName }} &bull; Time recorded automatically</span>
</div>
