@php
    $history = $this->historyProfile;
    $entries = $history['entries'];
@endphp
<section class="tala-profile-ledger" aria-labelledby="profile-history-heading">
    <header class="tala-profile-ledger__toolbar">
        <div><h2 id="profile-history-heading">Financial & student status history</h2><p class="tala-profile-muted">Recorded assessments and student status changes. Grades and enrollments have their own views.</p></div>
        @if ($profile['hasAssessments'] || $profile['hasLifecycleChanges'])
            <label class="tala-profile-control"><span>Record type</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="historyType"><option value="">All record types</option><option value="assessment">Assessments</option><option value="lifecycle">Student status changes</option></x-filament::input.select></x-filament::input.wrapper></label>
            <label class="tala-profile-control"><span>Term</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="historyTerm"><option value="">All recorded terms</option>@foreach ($history['termOptions'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @endif
    </header>
    @if ($entries->isNotEmpty())
        <div class="tala-profile-history-list tala-content-region" wire:loading.attr="aria-busy" wire:target="historyTerm,historyType,setHistoryPage">
            <x-content-progress label="Loading recorded history" wire:loading.delay.short.block wire:target="historyTerm,historyType,setHistoryPage" />
            @foreach ($entries as $entry)
                <article class="tala-profile-history-row" wire:key="history-{{ $entry['source'] }}-{{ $entry['id'] }}">
                    <div class="tala-profile-history-date"><span class="tala-profile-eyebrow">{{ $entry['dateLabel'] }}</span><strong>{{ $entry['date']?->format('M j, Y') ?? 'Not recorded' }}</strong></div>
                    <div><h3>{{ $entry['title'] }}</h3><p class="tala-profile-muted">{{ $entry['termLabel'] ?: 'Student-wide record' }}</p></div>
                    <x-filament::badge color="gray">{{ $entry['status'] }}</x-filament::badge>
                    @if ($entry['sourceUrl'])<x-filament::button tag="a" color="gray" :href="$entry['sourceUrl']" icon="heroicon-o-arrow-top-right-on-square">Open record</x-filament::button>@endif
                </article>
            @endforeach
        </div>
        <x-record-collection-footer :paginator="$entries" method="setHistoryPage" />
    @else
        <x-filament::empty-state :heading="$profile['hasAssessments'] || $profile['hasLifecycleChanges'] ? 'No matching history' : 'No financial or student status history yet'" :description="$profile['hasAssessments'] || $profile['hasLifecycleChanges'] ? 'Choose another term or record type.' : 'Assessments and student status changes will appear here when recorded. Nothing needs to be entered on this screen.'" icon="heroicon-o-clock" compact :contained="false" />
    @endif
</section>
