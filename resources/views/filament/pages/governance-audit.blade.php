<x-filament-panels::page>
    <div class="space-y-6">
        <div
            class="flex gap-2 overflow-x-auto rounded-xl border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900"
            role="tablist"
            aria-label="Governance evidence views"
        >
            @foreach ($this->tabs as $key => $label)
                <button
                    type="button"
                    role="tab"
                    wire:click="setActiveTab('{{ $key }}')"
                    aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                    @class([
                        'min-h-11 shrink-0 rounded-lg px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                        'bg-primary-600 text-white' => $activeTab === $key,
                        'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' => $activeTab !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($activeTab === \App\Actions\SystemAdministration\GovernanceEvidenceProjection::PrivacyRetention)
            <x-filament::section>
                <x-slot name="heading">Privacy and Retention Boundary</x-slot>
                <x-slot name="description">This view states the implemented MVP boundary; it does not issue a compliance verdict.</x-slot>

                <div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
                    <p><strong>Automatic retention disposal: Not provided in this MVP</strong></p>
                    <p><strong>External compliance status: Not evaluated by TALA</strong></p>
                    <p>
                        Retention schedules, privacy requests, legal holds, lawful disposition, provider approval,
                        custody, and secure disposal remain institutional responsibilities. TALA preserves the
                        authoritative records and evidence already stored; this page performs no disposal action.
                    </p>
                </div>
            </x-filament::section>
        @else
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
