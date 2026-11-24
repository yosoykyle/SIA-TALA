<x-filament-panels::page>
    <x-filament-actions::modals />

    <div class="tala-program-view">
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording and activation authority.</p>
            </x-tala-access-alert>
        @endif

        <section aria-label="Program summary" class="tala-task-surface tala-program-summary-card">
            @if (filled($program->description))
                <div style="display: grid; gap: 0.375rem;">
                    <div style="display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.625rem;">
                        <span class="tala-program-spec__term">
                            <x-filament::icon icon="heroicon-o-globe-alt" class="tala-program-spec__icon" aria-hidden="true" />
                            <span>Public program description</span>
                        </span>
                        <span class="tala-program-spec__note">· Shown on the public landing page and applicant catalog</span>
                    </div>
                    <p class="tala-program-summary__lead" style="color: var(--tala-text);">{{ $program->description }}</p>
                </div>
            @endif

            <div @class([
                'tala-program-specs-wrap',
                'tala-program-specs-wrap--divided' => filled($program->description),
            ])>
                <dl class="tala-program-specs">
                    <div class="tala-program-spec">
                        <dt class="tala-program-spec__term">
                            @if ($availability['available'])
                                <x-filament::icon icon="heroicon-o-check-circle" class="tala-program-spec__icon tala-program-summary__status--available" aria-hidden="true" />
                            @elseif ($program->is_active)
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-program-spec__icon tala-program-summary__status--warning" aria-hidden="true" />
                            @else
                                <x-filament::icon icon="heroicon-o-no-symbol" class="tala-program-spec__icon" aria-hidden="true" />
                            @endif
                            <span>Admissions</span>
                        </dt>
                        <dd class="tala-program-spec__value">
                            @if ($availability['available'])
                                <span class="tala-program-spec__primary tala-program-summary__status--available">Available in Admissions</span>
                                @if ($availability['batch_label'])
                                    <p class="tala-program-spec__note tabular-nums">{{ $availability['batch_label'] }}</p>
                                @endif
                            @elseif ($program->is_active)
                                <span class="tala-program-spec__primary tala-program-summary__status--warning">{{ $availability['reason'] }}</span>
                            @else
                                <span class="tala-program-spec__primary tala-program-summary__status--inactive">{{ $availability['reason'] }}</span>
                                @if ($continuingStudents > 0)
                                    <p class="tala-program-spec__note tabular-nums">{{ $continuingBatches }} {{ \Illuminate\Support\Str::plural('batch', $continuingBatches) }} continuing</p>
                                @endif
                            @endif
                        </dd>
                    </div>

                    <div class="tala-program-spec">
                        <dt class="tala-program-spec__term">
                            <x-filament::icon icon="heroicon-o-users" class="tala-program-spec__icon" aria-hidden="true" />
                            <span>Students</span>
                        </dt>
                        <dd class="tala-program-spec__value tabular-nums">
                            @if ($officialEnrolledCount !== $activeBatchStudents)
                                <div class="tala-program-spec__metric">
                                    <span class="tala-program-spec__figure">{{ number_format($officialEnrolledCount) }}</span>
                                    <span class="tala-program-spec__unit-label">enrolled this term</span>
                                </div>
                                <p class="tala-program-spec__note">{{ number_format($activeBatchStudents) }} in active batches</p>
                            @else
                                <div class="tala-program-spec__metric">
                                    <span class="tala-program-spec__figure">{{ number_format($officialEnrolledCount) }}</span>
                                    <span class="tala-program-spec__unit-label">{{ \Illuminate\Support\Str::plural('student', $officialEnrolledCount) }}</span>
                                </div>
                            @endif
                        </dd>
                    </div>

                    <div class="tala-program-spec">
                        <dt class="tala-program-spec__term">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="tala-program-spec__icon" aria-hidden="true" />
                            <span>Curricula</span>
                        </dt>
                        <dd class="tala-program-spec__value tabular-nums">
                            @if ($curriculaCount === 0)
                                <div class="tala-program-spec__metric">
                                    <span class="tala-program-spec__figure">0</span>
                                    <span class="tala-program-spec__unit-label">curricula</span>
                                </div>
                            @else
                                <div class="tala-program-spec__metric">
                                    <span class="tala-program-spec__figure">{{ $activeCurriculaCount }}</span>
                                    <span class="tala-program-spec__unit-label">active</span>
                                </div>
                                @if ($draftCurriculaCount > 0)
                                    <p class="tala-program-spec__note tala-program-summary__draft">
                                        {{ $draftCurriculaCount }} {{ \Illuminate\Support\Str::plural('draft', $draftCurriculaCount) }}
                                    </p>
                                @endif
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <x-filament::tabs label="Program details tabs" role="tablist" class="tala-segmented-tabs tala-program-tabs">
            <x-filament::tabs.item
                role="tab"
                id="program-tab-curricula"
                aria-controls="program-panel-curricula"
                :aria-selected="$activeTab === 'curricula' ? 'true' : 'false'"
                :active="$activeTab === 'curricula'"
                :badge="$curriculaCount"
                :badge-color="$activeTab === 'curricula' ? 'primary' : 'gray'"
                icon="heroicon-o-clipboard-document-list"
                wire:click="switchTab('curricula')"
                wire:loading.attr="disabled"
                wire:target="switchTab"
            >
                Curricula
            </x-filament::tabs.item>

            <x-filament::tabs.item
                role="tab"
                id="program-tab-sections"
                aria-controls="program-panel-sections"
                :aria-selected="$activeTab === 'sections' ? 'true' : 'false'"
                :active="$activeTab === 'sections'"
                :badge="$yearLevelsCount"
                :badge-color="$activeTab === 'sections' ? 'primary' : 'gray'"
                icon="heroicon-o-user-group"
                wire:click="switchTab('sections')"
                wire:loading.attr="disabled"
                wire:target="switchTab"
            >
                Year levels &amp; sections
            </x-filament::tabs.item>
        </x-filament::tabs>

        <section
            aria-label="Program details"
            class="tala-task-surface tala-program-panel"
            wire:loading.attr="aria-busy"
            wire:target="switchTab"
        >
            @if ($activeTab === 'curricula')
                <div
                    role="tabpanel"
                    id="program-panel-curricula"
                    aria-labelledby="program-tab-curricula"
                >
                    @include('filament.resources.programs.partials.curricula-tab')
                </div>
            @else
                <div
                    role="tabpanel"
                    id="program-panel-sections"
                    aria-labelledby="program-tab-sections"
                >
                    @include('filament.resources.programs.partials.sections-tab')
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
