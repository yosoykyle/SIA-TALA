<x-filament-panels::page>
    <x-filament-actions::modals />

    <div class="space-y-4">
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        <section aria-label="Programs & Curricula directory" class="tala-workbench-directory">
            @if (filled($referenceTermLabel ?? null))
                <p class="tala-ref-term-note" role="status" aria-live="polite">
                    <span class="tala-ref-term-note__label">Showing enrollment &amp; active year levels for</span>
                    <span class="tala-ref-term-note__value">{{ $referenceTermLabel }}</span>
                    <span class="tala-ref-term-note__hint">(change in Filters)</span>
                </p>
            @endif

            {{ $this->table }}
        </section>
    </div>
</x-filament-panels::page>
