<div class="tala-qualification-list">
    @forelse ($qualifications as $qualification)
        @php
            $isRequired = $qualification['treatment'] === \App\Models\ExternalCompetencyRequirement::TreatmentCompletionRequired;
        @endphp
        <article class="tala-qualification" wire:key="ext-comp-{{ $qualification['id'] }}">
            <div class="tala-qualification__body">
                <h3>{{ $qualification['qualification_label'] }}@if (filled($qualification['qualification_level']) && ! str_contains($qualification['qualification_label'], $qualification['qualification_level'])) {{ $qualification['qualification_level'] }}@endif</h3>
                <p class="tala-qualification__meta">{{ $qualification['linked_course_code'] ? 'Linked subject: '.$qualification['linked_course_code'] : 'Applies to whole curriculum' }}</p>
            </div>
            <div class="tala-qualification__trailing">
                <x-filament::badge :color="$isRequired ? 'warning' : 'gray'" size="sm">
                    {{ $isRequired ? 'Required for graduation' : 'Result recorded only' }}
                </x-filament::badge>
                @if ($canEdit)
                    <div class="tala-qualification__actions">
                        <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-pencil-square" wire:click="mountAction('editExternalCompetency', { requirementId: {{ $qualification['id'] }} })">
                            Edit<span class="tala-visually-hidden"> {{ $qualification['qualification_label'] }}</span>
                        </x-filament::button>
                        <x-filament::button type="button" size="sm" color="danger" outlined icon="heroicon-m-trash" wire:click="mountAction('removeExternalCompetency', { requirementId: {{ $qualification['id'] }} })">
                            Remove<span class="tala-visually-hidden"> {{ $qualification['qualification_label'] }}</span>
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </article>
    @empty
        <x-servitech-empty-state
            illustrated
            heading="No external qualifications yet"
            description="Add TESDA National Certificates or external assessments tied to this curriculum."
        >
            @if ($canEdit)
                <x-filament::button type="button" color="primary" icon="heroicon-m-plus" wire:click="mountAction('addExternalCompetency')">Add certificate</x-filament::button>
            @endif
        </x-servitech-empty-state>
    @endforelse
</div>
