<x-filament-panels::page>
    <x-filament-actions::modals />

    <div class="space-y-4">
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        <section aria-label="Programs & Curricula directory" class="tala-workbench-directory">
            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
