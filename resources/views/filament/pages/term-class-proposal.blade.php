<x-filament-panels::page class="tala-term-workspace tala-guide-class-proposal">
    @include('filament.pages.partials.term-workspace-header')

    <div class="tala-guide-proposal-work" x-data="{ query: '', matched: 0, total: 0,
        filterClasses() {
            const list = $el.querySelector('[data-class-proposal] > .fi-fo-repeater-items');
            const items = list ? Array.from(list.children) : [];
            const search = this.query.trim().toLocaleLowerCase();
            this.total = items.length;
            this.matched = 0;
            items.forEach(item => {
                const label = item.querySelector('.fi-fo-repeater-item-header')?.textContent || '';
                item.hidden = search !== '' &amp;&amp; !label.toLocaleLowerCase().includes(search);
                if (!item.hidden) this.matched++;
            });
        }
    }" x-init="$nextTick(() => filterClasses())" x-effect="query; $nextTick(() => filterClasses())" x-on:form-validation-error.window="query = ''">
        @if ($cohorts->isEmpty())
            <div class="tala-task-finding"><p>Record the student sections and their confirmed learners before preparing classes.</p><x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $termId, 'classesView' => 'cohorts'])">Record student sections</x-filament::button></div>
        @endif
        <p class="tala-work-footnote">Add the courses that will run. Existing classes stay unchanged until you prepare or cancel them.</p>
        <div class="tala-proposal-search"><label for="class-proposal-search">Find a course or program</label><x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass"><x-filament::input id="class-proposal-search" type="search" x-model.debounce.150ms="query" /></x-filament::input.wrapper><p class="tala-work-footnote" aria-live="polite"><span x-text="matched + ' of ' + total + ' proposal classes shown.'"></span> Search changes this view only. All decisions are retained.</p></div>
        <p x-cloak x-show="total > 0 &amp;&amp; matched === 0" role="status">No course matches this search. Clear the search to see all choices.</p>

        {{ $this->form }}

        <footer class="tala-guide-footer">
            <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $termId, 'classesView' => 'classes'])">Cancel and return to classes</x-filament::button>
            {{ $this->confirmProposalAction }}
        </footer>
        @if (! $this->hasProposalChanges())
            <p class="tala-guide-proposal-heading-copy" role="status">Add a course, prepare a draft or cancel an existing class to enable review.</p>
        @endif
    </div>

    <details class="tala-guide-reference-footer tala-planning-details"><summary>Curriculum and recorded demand</summary>
<div class="tala-planning-reveal">
        <p><strong>{{ $approvedCurriculumCount }} approved entries</strong> · {{ $matchingCurriculumCount }} match this Term type</p>
        {{ $this->viewApprovedCurriculumAction }}
        <p class="tala-guide-proposal-demand">Recorded demand: {{ $demandEvidence['ready_applicants'] }} ready applicants for this Term · {{ $demandEvidence['continuing_profiles'] }} active continuing profiles institution-wide · {{ count($demandEvidence['unmet']) }} unmet course requests. Use recorded learner counts when preparing each class.</p>
        @if ($demandEvidence['unmet'])
            <div class="tala-guide-unmet-requests" aria-label="Unmet course requests">
                <h2>Unmet course requests</h2>
                @foreach ($demandEvidence['unmet'] as $request)
                    <p>{{ $request['course'] }} · {{ $request['section'] }} · {{ $request['unmet_demand'] }} learner — {{ $request['recovery'] }}</p>
                @endforeach
            </div>
        @endif

</div>
</details>

    <details class="tala-guide-proposal-guidance tala-planning-details">
        <summary>Curriculum, demand and preparation guidance</summary>
<div class="tala-planning-reveal">
        <p>Link student sections to share a class, or add another class to split learners. Learner counts across shared or split classes cannot exceed confirmed counts for a student section.</p>
        <p>Classes use approved curriculum and active course revisions. Additional classes require their approved source reference. Faculty and room readiness are checked before timetable generation.</p>

</div>
</details>

    <x-filament-actions::modals />
</x-filament-panels::page>
