<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-3">
        <div class="tala-faculty-work-header">@include('filament.components.faculty-term-context', ['contextStatus' => $version ? 'Official version '.$version->version.' · '.$assignmentCount.' assigned meetings' : ($term ? 'No official meetings assigned in this Term' : null), 'showExaminationContext' => (bool) $term, 'syntheticReview' => $presentation['synthetic'] ?? false])
        @include('filament.components.faculty-task-navigation')</div>
        @if ($revisionEvents->isNotEmpty())<p class="text-sm" role="status">Published changes affect your assignments. The schedule shows current official meetings. <a href="#faculty-published-changes" class="underline">View changes</a></p>@endif
        <section class="tala-schedule-work" aria-label="Own official meetings" x-data="{
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
        @if ($revisionEvents->isNotEmpty())<details id="faculty-published-changes" class="text-sm"><summary>Published changes affecting this schedule</summary><ul class="mt-3 space-y-3">@foreach ($revisionEvents as $event)<li><strong>{{ \App\Models\ScheduleRevisionEvent::changeTypeOptions()[$event->change_type] ?? str($event->change_type)->headline() }}</strong> · Effective {{ $event->effective_date->format('M j, Y') }}<p>{{ $event->reason }}</p></li>@endforeach</ul></details>@endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
