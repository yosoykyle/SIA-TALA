<button
    type="button"
    class="tala-drag-handle inline-flex h-8 w-8 cursor-grab items-center justify-center rounded-md text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 active:cursor-grabbing dark:text-zinc-500 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
    draggable="true"
    data-tala-drag-handle
    data-record-id="{{ $getRecord()->getKey() }}"
    aria-label="Drag or use Up/Down arrows to reorder"
    title="Drag or use Up/Down arrows to reorder"
>
    <x-filament::icon icon="heroicon-m-bars-2" class="h-4 w-4" aria-hidden="true" />
</button>
