@assets
    <link rel="stylesheet" href="{{ asset('css/tala-catalog.css').'?v='.filemtime(public_path('css/tala-catalog.css')) }}">
@endassets

<x-filament-panels::page>
    <x-filament-actions::modals />

    <div class="tala-workspace-launcher tala-catalog-overview">
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        <div class="tala-workspace-launcher__main" aria-label="Catalog coverage">
            @foreach ($coverageCards as $card)
                <x-workspace-card
                    wire:key="coverage-{{ $card['key'] }}"
                    :href="$card['url']"
                    :icon="$card['icon']"
                    :label="$card['label']"
                    :figure="$card['count']"
                    :figure-label="$card['figure_label']"
                    :description="$card['summary']"
                    :action="$card['action_label']"
                    :action-color="$card['featured'] ? 'primary' : 'gray'"
                    :featured="$card['featured']"
                />
            @endforeach
        </div>

        @if (! $hasPrograms)
            @if ($readOnly)
                <div class="tala-task-surface tala-catalog-empty-card">
                    <x-servitech-empty-state
                        illustrated
                        heading="No programs recorded yet"
                        description="Programs and study plans appear here once the Registrar creates them."
                    />
                </div>
            @else
                <div class="tala-task-surface tala-catalog-empty-card servitech-journey-scene tala-catalog-onboarding">
                    <x-servitech-journey-scenery />
                    <x-servitech-empty-state
                        illustrated
                        heading="Set up your first program"
                        description="Add a degree or diploma program first, then build its study plan across years and semesters."
                    >
                        {{ $this->createProgramAction }}
                        <x-filament::button tag="a" :href="$subjectsUrl" color="gray">Browse subjects</x-filament::button>
                    </x-servitech-empty-state>
                </div>
            @endif
        @else
            <section class="tala-catalog-curricula-section" aria-label="Curricula overview">
                @if (! $readOnly)
                    <x-filament::tabs label="Curricula view" class="tala-segmented-tabs">
                        <x-filament::tabs.item
                            :active="$activeTab === 'draft'"
                            :badge="$draftCurriculaCount"
                            wire:click="switchTab('draft')"
                        >
                            Draft curricula
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            :active="$activeTab === 'active'"
                            :badge="$activeCurriculaCount"
                            wire:click="switchTab('active')"
                        >
                            Active curricula
                        </x-filament::tabs.item>
                    </x-filament::tabs>
                @endif

                {{ $this->table }}
            </section>
        @endif
    </div>
</x-filament-panels::page>
