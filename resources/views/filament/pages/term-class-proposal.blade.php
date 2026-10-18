<x-filament-panels::page class="tala-term-workspace tala-guide-class-proposal">
    @include('filament.pages.partials.term-workspace-header')

    <div class="tala-guide-proposal-work">
        <p class="tala-guide-proposal-heading-copy">Choose “Prepare class” to enter class details. Leave “Do not add a class” to make no change.</p>

        {{ $this->form }}

        <footer class="tala-guide-footer">
            <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $termId, 'classesView' => 'classes'])">Cancel and return to classes</x-filament::button>
            {{ $this->confirmProposalAction }}
        </footer>
        @if (! $this->hasProposalChanges())
            <p class="tala-guide-proposal-heading-copy" role="status">Choose “Prepare class” or cancel an existing class to enable review.</p>
        @endif
    </div>

    <footer class="tala-guide-reference-footer" aria-label="Curriculum reference and recorded demand">
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
    </footer>

    <details class="tala-guide-proposal-guidance tala-planning-details">
        <summary>Curriculum, demand and preparation guidance</summary>
        <p>Link student sections to share a class, or add another class to split learners. Learner counts across shared or split classes cannot exceed confirmed counts for a student section.</p>
        <p>Classes use approved curriculum and active course revisions. Additional classes require their approved source reference. Faculty and room readiness are checked before timetable generation.</p>
    </details>

    <x-filament-actions::modals />
</x-filament-panels::page>
