@php
    $enrollmentView = $this->enrollmentProfile;
    $enrollment = $enrollmentView['selected'];
    $enrollmentContext = $enrollmentView['context'];
    $official = $enrollment?->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled;
@endphp
<section class="tala-profile-ledger" aria-labelledby="profile-enrollment-heading">
    <header class="tala-profile-ledger__toolbar">
        <div><h2 id="profile-enrollment-heading">Enrollment</h2><p class="tala-profile-muted">Enrollment status and section for each recorded term.</p></div>
        @if (count($enrollmentView['termOptions']) > 1)
            <label class="tala-profile-control"><span>Enrolled term</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="enrollment">@if ($this->enrollment === null)<option value="">{{ $enrollmentView['termOptions'][$enrollment?->id] ?? 'No enrolled term' }}</option>@endif@foreach ($enrollmentView['termOptions'] as $id => $label)@if ($this->enrollment !== null || (int) $id !== (int) $enrollment?->id)<option value="{{ $id }}">{{ $label }}</option>@endif@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @endif
    </header>
    @if ($enrollment)
        <div class="tala-profile-enrollment-body tala-content-region" wire:loading.attr="aria-busy" wire:target="enrollment">
            <x-content-progress label="Loading selected enrollment" wire:loading.delay.short.block wire:target="enrollment" />
            <div class="tala-profile-enrollment-main">
                <div class="tala-profile-action-row"><div><span class="tala-profile-eyebrow">{{ $enrollment->term?->academicYear?->label }}</span><h3>{{ $enrollment->term?->label }}</h3></div><x-filament::badge :color="$official ? 'success' : 'gray'">{{ $enrollmentContext['enrollment_status_label'] }}</x-filament::badge></div>
                <x-enrollment-context :context="$enrollmentContext" :term-label="$enrollmentView['termOptions'][$enrollment->id]" :official="$official" compact />
                @if ($enrollmentView['sourceUrl'])<div class="tala-action-block"><x-filament::button tag="a" :href="$enrollmentView['sourceUrl']" icon="heroicon-o-arrow-top-right-on-square">Open enrollment</x-filament::button></div>@endif
            </div>
            <aside class="tala-profile-ledger__context">
                <div><span class="tala-profile-eyebrow">Term grouping</span><h3>Student section</h3></div>
                <strong>{{ $enrollmentContext['student_section_label'] }}</strong>
                <p class="tala-profile-muted">A section groups students for this term. Enrolled subjects determine the classes they attend.</p>
                @if ($this->recordStudentSectionAction->isVisible()){{ $this->recordStudentSectionAction->label($enrollmentContext['student_section_id'] ? 'Change section' : 'Assign section') }}@elseif (! $official)<p class="tala-profile-muted">Section assignment becomes available for an official enrollment.</p>@endif
            </aside>
        </div>
        @if ($enrollment->canonical_outcome === \App\Models\Enrollment::OutcomeInProgress)
            <div class="tala-profile-ledger__support"><x-workflow-handoff :current-owner="$enrollmentContext['responsible_office']" :current-task="$enrollmentContext['next_action']" :next-owner="$enrollmentContext['responsible_office'] === 'Accounting Office' ? 'Registrar' : null" :next-task="$enrollmentContext['responsible_office'] === 'Accounting Office' ? 'Continue enrollment when cleared.' : null" /></div>
        @endif
    @else
        <x-filament::empty-state heading="No enrollment recorded" description="This student's enrolled terms will appear here when registration is recorded." icon="heroicon-o-academic-cap" compact :contained="false" />
    @endif
</section>
