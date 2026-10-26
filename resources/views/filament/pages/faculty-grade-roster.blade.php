<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $roster = $membership['roster'] ?? null;
        $designated = $roster !== null && (int) $roster->teachingAssignment?->faculty_user_id === (int) auth()->id();
    @endphp

    @if ($roster === null)
        <section class="faculty-class-worklist" aria-labelledby="faculty-class-heading">
            <h2 id="faculty-class-heading">Choose a class</h2>
            <p>Review your students and record results for an assigned class.</p>
            <form method="get" class="faculty-class-search">
                <input type="hidden" name="classes" value="1">
                <div><label for="faculty-class-term">Academic year and term</label><x-filament::input.wrapper><x-filament::input.select id="faculty-class-term" name="term"><option value="">All assigned terms</option>@foreach ($classTerms as $classTerm)<option value="{{ $classTerm->id }}" @selected($classTermFilter === $classTerm->id)>{{ $classTerm->academicYear?->label }} · {{ $classTerm->label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
                <div><label for="faculty-class-search">Find a class</label><x-filament::input.wrapper><x-filament::input id="faculty-class-search" name="q" type="search" :value="$classSearch" placeholder="Course title or class code" /></x-filament::input.wrapper></div>
                <x-filament::button type="submit" color="gray">Find classes</x-filament::button>
            </form>
            <p class="faculty-class-count">{{ $classRows->count() }} {{ str('class')->plural($classRows->count()) }}</p>
            @forelse ($classRows as $classRow)
                @php
                    $classRoster = $classRow['roster'];
                @endphp
                <article class="faculty-class-row">
                    <div><h3>{{ $classRoster->termOffering?->curriculumEntry?->courseSpecification?->title }}</h3><p>{{ $classRoster->section?->code }} · {{ $classRoster->termOffering?->term?->label }}</p></div>
                    <div class="faculty-class-members">@if ($classRow['current'])<strong>{{ $classRow['count'] }}</strong><span>{{ str('student')->plural($classRow['count']) }}</span>@else<span>Membership needs an update</span>@endif</div>
                    <x-filament::badge color="gray">{{ str($classRoster->state)->lower()->replace('_', ' ')->ucfirst() }}</x-filament::badge>
                    <x-filament::button tag="a" color="gray" :href="$this::getUrl(['roster' => $classRoster->id])" :aria-label="'Open class: '.$classRoster->section?->code">Open class</x-filament::button>
                </article>
            @empty
                <section class="faculty-membership-message"><h3>No assigned roster</h3><p>Current designated and co-Faculty assignments appear here after the Registrar records them. If you used filters, try another term or class.</p></section>
            @endforelse
        </section>
    @elseif (!$membership['isCurrent'])
        <section class="faculty-membership-message" role="status"><h2>Class membership needs an update</h2><p>Ask the Registrar to refresh this class roster, then reload this page. The current learner list and outputs will be available after the records agree.</p></section>
    @else
        <div class="faculty-membership-context"><strong>{{ $membership['count'] }} enrolled {{ str('student')->plural($membership['count']) }}</strong><span>{{ $designated ? 'Designated faculty' : 'View-only co-faculty' }}</span><x-filament::badge color="gray">{{ str($roster->state)->lower()->replace('_', ' ')->ucfirst() }}</x-filament::badge></div>
        @if ($this->canEditSelectedRoster())
            @php
                $recordedResults = collect($data['rows'] ?? [])->filter(fn ($row) => filled($row['final_result'] ?? null))->count();
            @endphp
            <dl class="faculty-result-progress" aria-label="Draft result entry"><div><dt>Results recorded</dt><dd>{{ $recordedResults }}</dd></div><div><dt>To complete</dt><dd>{{ max(0, $membership['count'] - $recordedResults) }}</dd></div></dl>
        @endif
        @if ($membership['count'] === 0)
            <section class="faculty-membership-message" role="status"><h2>No students enrolled yet</h2><p>Officially enrolled students will appear here after the Registrar finalizes their enrollment.</p></section>
        @endif
        <div class="faculty-membership-window">@if ($membership['count'] > 0)<p>{{ $this->canEditSelectedRoster() ? 'Grade entry is open for this class.' : (!$designated ? 'This class roster is view-only. The designated faculty records results.' : ($period['status'] !== 'Available' ? 'Grade entry is closed. Ask the Registrar for the approved examination dates.' : 'This class roster is read-only. See the grade-entry dates and assignment below.')) }}</p>@endif<details><summary>Grade entry dates and source</summary><x-examination-period-summary :projection="$period" /></details></div>
        @if ($membership['count'] > 0)
        @if (!$this->canEditSelectedRoster())
            <section class="faculty-membership-list tala-task-surface" aria-labelledby="faculty-membership-heading"><h2 id="faculty-membership-heading">Class roster</h2>
                <table><thead><tr><th scope="col">Student number</th><th scope="col">Legal name</th><th scope="col">Final result</th></tr></thead><tbody>@foreach ($roster->rows as $row)@php $student = $row->courseEnrollment?->enrollment?->studentProfile; @endphp<tr><td data-label="Student number">{{ $student?->student_number }}</td><th scope="row" data-label="Legal name">{{ collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', ') }}</th><td data-label="Final result">{{ $row->final_result ?? 'Not recorded' }}@if ($row->final_result === 'INC' && filled($row->inc_completion_note))<span>{{ $row->inc_completion_note }}</span>@endif</td></tr>@endforeach</tbody></table><p class="faculty-membership-note">Only current official class members are shown.</p>
            </section>
        @else
        <form wire:submit="saveDraft" class="space-y-6">
            {{ $this->form }}

            @if ($this->canEditSelectedRoster())
                <div class="tala-action-block">
                    <x-filament::button
                        type="submit"
                        color="gray"
                        icon="heroicon-m-bookmark-square"
                        wire:loading.attr="disabled"
                        wire:target="saveDraft"
                    >
                        Save draft
                    </x-filament::button>
                </div>
            @endif
        </form>
        @endif
        @endif
        <details class="faculty-membership-source"><summary>Roster source details</summary><dl><div><dt>Designated faculty</dt><dd>{{ $roster->teachingAssignment?->faculty?->name ?? $roster->faculty?->name ?? 'Not recorded' }}</dd></div>@if (filled($roster->teachingAssignment?->authority_reference))<div><dt>Assignment approval</dt><dd>{{ $roster->teachingAssignment->authority_reference }}</dd></div>@endif<div><dt>Roster version</dt><dd>{{ $roster->current_version_number }}</dd></div><div><dt>Membership reference</dt><dd>{{ $roster->membership_signature }}</dd></div></dl></details>
    @endif
</x-filament-panels::page>
