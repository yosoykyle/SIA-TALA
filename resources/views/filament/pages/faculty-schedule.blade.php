<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-3">
        @if (! $term)
            @include('filament.components.faculty-term-context', ['emptyHeading' => 'Choose a Term to view your schedule', 'emptyDescription' => 'Select an Academic Year and Term to see your official teaching meetings.'])
        @else
        <div class="tala-faculty-work-header">@include('filament.components.faculty-term-context', ['contextStatus' => $version ? 'Current official schedule' : ($term ? 'No official meetings assigned in this Term' : null), 'showExaminationContext' => (bool) $term, 'syntheticReview' => $presentation['synthetic'] ?? false])
        </div>
        @if ($revisionEvents->isNotEmpty())<p class="text-sm" role="status">Published changes affect your assignments. The schedule shows current official meetings. <a href="#faculty-published-changes" class="underline">View changes</a></p>@endif
        @if ($assignmentCount === 0)
            <section class="tala-faculty-no-meetings" aria-labelledby="faculty-no-meetings-heading">
                <x-heroicon-o-calendar-days aria-hidden="true" />
                <h2 id="faculty-no-meetings-heading">No official meetings assigned in this Term</h2>
                <p>Your teaching meetings will appear after the Registrar publishes an assignment for this exact Term.</p>
                <x-filament::link :href="\App\Filament\Pages\MyAvailability::getUrl(['termId' => $term->id])">View My availability</x-filament::link>
            </section>
        @else
        <dl class="tala-faculty-schedule-figures" aria-label="Official teaching assignment summary">
            <div><dt>Classes</dt><dd>{{ $classCount }}</dd></div>
            <div><dt>Weekly meetings</dt><dd>{{ $assignmentCount }}</dd></div>
            <div><dt>Teaching days</dt><dd>{{ $teachingDayCount }}</dd></div>
        </dl>
        @if ($scheduleView === 'week' && $this->scheduleCriteria() !== [])
            <p class="tala-schedule-filter-notice" role="status">Showing selected meetings: {{ $this->scheduleCriteriaDescription() }}. <button type="button" wire:click="clearScheduleFilters">Clear filters</button></p>
        @endif
        <section class="tala-schedule-work {{ $scheduleView === 'week' ? 'tala-schedule-week' : 'tala-schedule-list' }}" aria-label="Own official meetings" x-data="{
            observer: null,
            labelSearch() {
                const input = this.$el.querySelector('.fi-ta-search-field input[type=search]');
                const label = 'Search your official schedule by course title or code, class code or room code';
                if (input &amp;&amp; input.getAttribute('aria-label') !== label) {
                    input.setAttribute('aria-label', label);
                }
            },
            init() {
                this.labelSearch();
                this.observer = new MutationObserver(() => this.labelSearch());
                this.observer.observe(this.$el, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-label'] });
            },
            destroy() { this.observer?.disconnect(); }
        }">{{ $this->table }}</section>
        @endif
        @if ($revisionEvents->isNotEmpty())<details id="faculty-published-changes" class="text-sm"><summary>Published changes affecting this schedule</summary><ul class="mt-3 space-y-3">@foreach ($revisionEvents as $event)<li><strong>{{ \App\Models\ScheduleRevisionEvent::changeTypeOptions()[$event->change_type] ?? str($event->change_type)->headline() }}</strong> · Effective {{ $event->effective_date->format('M j, Y') }}<p>{{ $event->reason }}</p></li>@endforeach</ul></details>@endif
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
