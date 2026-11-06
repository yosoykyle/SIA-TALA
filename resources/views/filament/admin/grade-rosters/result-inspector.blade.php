<div class="fi-resource-relation-manager">
    @if ($records->isEmpty())
        <section class="servitech-empty-collection" aria-label="Released students">
            <header class="tala-record-browser-header">
                <h2>Students</h2>
                <label for="released-student-search-{{ $this->getId() }}" class="sr-only">Find a student</label>
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input id="released-student-search-{{ $this->getId() }}" type="search" wire:model.live.debounce.350ms="tableSearch" placeholder="Name or student number" />
                </x-filament::input.wrapper>
            </header>
            <x-servitech-empty-state :heading="filled($this->tableSearch) ? 'No matching students' : 'No released results available'" :description="filled($this->tableSearch) ? 'Clear the search or try a different name or student number.' : 'This class is marked as released, but no current released student results are available. Ask the Registrar to review the class source.'" icon="heroicon-o-magnifying-glass">
                @if (filled($this->tableSearch))<x-filament::button color="gray" wire:click="resetTableSearch">Clear search</x-filament::button>@endif
            </x-servitech-empty-state>
        </section>
    @else
    <x-record-workspace>
        <x-slot:browser aria-label="Released students">
            <header class="tala-record-browser-header">
            <h2>Students <span>{{ $records->total() }}</span></h2>
            <div class="tala-record-search">
                <label for="released-student-search-{{ $this->getId() }}" class="sr-only">Find a student</label>
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input id="released-student-search-{{ $this->getId() }}" type="search" wire:model.live.debounce.350ms="tableSearch" placeholder="Name or student number" />
                </x-filament::input.wrapper>
            </div>
            </header>
            <ul class="tala-record-list">
                @forelse ($records as $row)
                    @php($student = $row->courseEnrollment?->enrollment?->studentProfile)
                    <li wire:key="released-student-{{ $row->id }}">
                        <x-record-option wire:click="selectResult({{ $row->id }})" aria-controls="released-result-inspector-{{ $this->getId() }}" :selected="$selected?->id === $row->id" :result="$row->current_outcome_code" :result-description="$row->current_outcome_code === 'INC' ? 'Incomplete' : null">
                            <strong>{{ collect([$student?->last_name, $student?->first_name])->filter()->implode(', ') }}</strong><small>{{ $student?->student_number }}</small>
                        </x-record-option>
                    </li>
                @empty
                    <li class="tala-record-empty"><strong>{{ filled($this->tableSearch) ? 'No matching students' : 'No released results' }}</strong><p>{{ filled($this->tableSearch) ? 'Try a different name or student number.' : 'Released class results will appear here.' }}</p></li>
                @endforelse
            </ul>

        </x-slot:browser>
        <x-slot:inspector id="released-result-inspector-{{ $this->getId() }}" aria-label="Selected released result">
            @if ($selected)
                @php($student = $selected->courseEnrollment?->enrollment?->studentProfile)
                <x-record-identity :name="collect([$student?->last_name, $student?->first_name])->filter()->implode(', ')" :number="$student?->student_number" :program="$student?->program?->name" :initials="str($student?->first_name)->substr(0, 1).str($student?->last_name)->substr(0, 1)" :photo-url="filled($student?->user?->avatar_path) ? route('grade-rosters.student-photo', ['roster' => $selected->grade_roster_id, 'row' => $selected->id]) : null" />
                @php($correction = (clone $this->getTable()->getAction('recordCorrection'))->record($selected)->button()->label('Record correction'))
                @php($deadline = (clone $this->getTable()->getAction('amendIncDeadline'))->record($selected)->button()->label('Change deadline'))
                <x-record-result :result="$selected->current_outcome_code" :completion="$followUp" :released-on="$selected->outcomeEvents->first()?->released_at?->timezone('Asia/Manila')->format('M j, Y') ?? $selected->released_at?->timezone('Asia/Manila')->format('M j, Y')" :deadline-action="$deadline->isVisible() ? $deadline : null">
                    <x-slot:actions>
                        @if ($correction->isVisible())
                            {{ $correction }}
                        @endif
                    </x-slot:actions>
                </x-record-result>
                @if ($followUp)
                    <x-inc-follow-up :context="$followUp" :show-deadline="false" compact />
                @else
                    <x-workflow-handoff current-owner="Official result released" current-task="Registrar records a correction only when the institution has authorized a change. Earlier results stay in history." />
                @endif
                @php($remainingActions = collect($this->getTable()->getRecordActions())->reject(fn ($action) => in_array($action->getName(), ['recordCorrection', 'amendIncDeadline'], true))->map(fn ($action) => (clone $action)->record($selected)->button())->filter(fn ($action) => $action->isVisible()))
                @if ($remainingActions->isNotEmpty())
                    <x-record-actions>
                        @foreach ($remainingActions as $action){{ $action }}@endforeach
                    </x-record-actions>
                @endif
                <x-result-history :events="$history" :fallback-result="$selected->current_outcome_code" :fallback-version="$this->getOwnerRecord()->current_version_number" />
            @else
                <div class="tala-record-empty"><x-filament::icon icon="heroicon-o-academic-cap" class="size-8" /><h2>No result selected</h2><p>{{ filled($this->tableSearch) ? 'Clear or change the search to find a student in this class.' : 'This class has no released student results to inspect.' }}</p></div>
            @endif
        </x-slot:inspector>
        <x-slot:collectionFooter><x-record-navigation :records="$records" :selected="$selected" select-action="selectResult" /></x-slot:collectionFooter>
    </x-record-workspace>
    @endif
    <x-filament-actions::modals />
    <x-filament-panels::unsaved-action-changes-alert />
</div>
