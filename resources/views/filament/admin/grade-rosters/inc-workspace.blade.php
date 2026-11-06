@php
    $selected = $records->firstWhere('id', $this->selectedIncResultId) ?? $records->first();
    $student = $selected?->courseEnrollment?->enrollment?->studentProfile;
    $offering = $selected?->roster?->termOffering;
    $releasedAt = $selected?->outcomeEvents->whereNotNull('released_at')->sortByDesc('id')->first()?->released_at ?? $selected?->released_at;
    $history = $selected ? $this->resultHistoryFor($selected->id) : null;
    $followUp = $selected ? \App\Filament\Resources\GradeRosters\Tables\ReleasedResultsTable::followUp($selected) : null;
@endphp
@if ($records->isEmpty())
    @php
        $scope = $this->getTableFilterState('result_scope')['value'] ?? 'inc';
        $hasCriteria = $this->hasTableSearch() || filled($this->getTableFilterState('term')['value'] ?? null);
    @endphp
    <x-servitech-empty-state :illustrated="! $hasCriteria && $scope === 'all'" :heading="$hasCriteria ? 'No released course results match' : ($scope === 'inc' ? 'No INC results in this scope' : 'No released course results yet')" :description="$hasCriteria ? $getEmptyStateDescription() : ($scope === 'inc' ? 'Choose Grade correction to inspect other released results, or change the term.' : 'Results appear here after the Registrar releases a class roster.')" icon="heroicon-o-magnifying-glass">
        @if ($this->hasTableSearch())<x-filament::button color="gray" wire:click="resetTableSearch" icon="heroicon-m-x-mark">Clear search</x-filament::button>@endif
        @if (filled($this->getTableFilterState('term')['value'] ?? null))<x-filament::button color="gray" wire:click="resetTableFiltersForm" icon="heroicon-m-arrow-path">Clear filters</x-filament::button>@endif
    </x-servitech-empty-state>
@else
<x-record-workspace class="tala-inc-workspace" collection-label="course results">
    <x-slot:browser aria-label="Course results">
        <header class="tala-record-browser-header">
            <h2>Course results <span>{{ $records->total() }}</span></h2>
            <p>Each entry is one student’s result in one class.</p>
        </header>
        <ul class="tala-record-list">
            @foreach ($records as $row)
                @php($learner = $row->courseEnrollment?->enrollment?->studentProfile)
                <li wire:key="inc-result-{{ $row->id }}">
                    <x-record-option wire:click="selectIncResult({{ $row->id }})" aria-controls="inc-result-inspector" :selected="$selected?->id === $row->id" :result="$row->current_outcome_code" :result-description="$row->current_outcome_code === 'INC' ? 'Incomplete' : 'Released'">
                            <strong>{{ collect([$learner?->last_name, $learner?->first_name])->filter()->implode(', ') }}</strong>
                            <small>{{ $learner?->student_number }}</small>
                            <x-slot:context><strong>{{ collect([$row->roster?->termOffering?->curriculumEntry?->courseSpecification?->course?->code, $row->roster?->termOffering?->curriculumEntry?->courseSpecification?->title])->filter()->implode(' · ') }}</strong>
                            <small>{{ collect([$row->roster?->section?->code, $row->roster?->termOffering?->term?->label, $row->roster?->termOffering?->term?->academicYear?->label])->filter()->implode(' · ') }}</small></x-slot:context>
                        </x-record-option>
                </li>
            @endforeach
        </ul>
    </x-slot:browser>
    <x-slot:inspector id="inc-result-inspector" :aria-label="$selected ? 'Selected course result' : 'No course result selected'">
        @if ($selected)
            <x-record-identity :name="collect([$student?->last_name, $student?->first_name])->filter()->implode(', ')" :number="$student?->student_number" :program="$student?->program?->name" :initials="str($student?->first_name)->substr(0, 1).str($student?->last_name)->substr(0, 1)" :photo-url="filled($student?->user?->avatar_path) ? route('grade-rosters.student-photo', ['roster' => $selected->grade_roster_id, 'row' => $selected->id]) : null" />
            <div class="tala-inc-course tala-record-context-row">
                <h3>{{ collect([$offering?->curriculumEntry?->courseSpecification?->course?->code, $offering?->curriculumEntry?->courseSpecification?->title])->filter()->implode(' · ') }}</h3>
                <p>{{ collect([$selected->roster?->section?->code, $offering?->term?->label, $offering?->term?->academicYear?->label])->filter()->implode(' · ') }}</p>
                @php($classAction = (clone $this->getTable()->getAction('viewClass'))->record($selected)->label('Open class context')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')->button())
                {{ $classAction }}
            </div>
            @php($correction = (clone $this->getTable()->getAction('recordCorrection'))->record($selected)->button()->label('Record correction'))
            @php($deadline = (clone $this->getTable()->getAction('amendIncDeadline'))->record($selected)->button()->label('Change deadline'))
            <x-record-result :result="$selected->current_outcome_code" :released-on="$releasedAt?->timezone('Asia/Manila')->format('M j, Y')" :completion="$followUp" :description="$selected->current_outcome_code === 'INC' ? 'Incomplete grade' : 'Official course result'" :deadline-action="$deadline->isVisible() ? $deadline : null">
                <x-slot:actions>
                    @if ($correction->isVisible())
                        {{ $correction }}
                    @endif
                </x-slot:actions>
            </x-record-result>
            @if ($followUp)
                <x-inc-follow-up :context="$followUp" :show-deadline="false" compact />
            @else
                <div class="tala-review-feedback"><x-filament::icon icon="heroicon-o-document-check" class="size-5" /><div><h3>Released grade</h3><p>Record a correction only when the institution has authorized the change. The earlier result stays in history.</p></div></div>
            @endif
            @php($remainingActions = collect($this->getTable()->getRecordActions())->reject(fn ($action) => in_array($action->getName(), ['viewClass', 'recordCorrection', 'amendIncDeadline'], true))->map(fn ($action) => (clone $action)->record($selected)->button())->filter(fn ($action) => $action->isVisible()))
            @if ($remainingActions->isNotEmpty())
                <x-record-actions>
                    @foreach ($remainingActions as $action){{ $action }}@endforeach
                </x-record-actions>
            @endif
            <x-result-history :events="$history" :fallback-result="$selected->current_outcome_code" :fallback-version="$selected->roster->current_version_number" />
        @else
            <div class="tala-record-empty"><x-filament::icon icon="heroicon-o-academic-cap" class="size-8" /><h2>No result selected</h2><p>Change the search or filters to find a released course result.</p></div>
        @endif
    </x-slot:inspector>
    <x-slot:collectionFooter><x-record-navigation :records="$records" :selected="$selected" select-action="selectIncResult" noun="course results" /></x-slot:collectionFooter>
</x-record-workspace>

@endif
