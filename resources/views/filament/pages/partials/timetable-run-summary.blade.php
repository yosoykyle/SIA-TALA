<section class="tala-task-surface tala-run-introduction" aria-label="Timetable review summary"
    @if (in_array($run->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true)) wire:poll.5s.visible="refreshScheduleRun" @endif>
    @if (in_array($run->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true))
        <x-tala-operation-status :run="$run" />
    @elseif ($wasPublished)
        <div class="tala-evidence-title"><x-filament::icon icon="heroicon-o-check-badge" /><div>
            <h2>{{ $publishedVersion ? ($publishedVersion->state === 'Published' ? 'Official timetable' : 'Historical timetable').' · v'.$publishedVersion->version : 'Published timetable record' }}</h2>
            <p class="tala-work-footnote">Published {{ \App\Support\DisplayDateTime::format($publishedVersion?->published_at ?? $run->published_at) }} · Asia/Manila · {{ $run->publisher?->name ?? 'Publisher not recorded' }}</p>
        </div></div>
        <p>The issued timetable is separate from its generation record. Later approved changes preserve earlier versions.</p>
        <div class="tala-evidence-actions">
            <x-filament::button tag="a" color="primary" icon="heroicon-o-table-cells" :href="$this->termPlanningReturnUrl()">Open issued timetable</x-filament::button>
            @if ($publishedVersion)<x-filament::button tag="a" color="gray" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $publishedVersion])" target="_blank">Print this version</x-filament::button>@endif
        </div>
    @else
        <div class="tala-task-heading"><h2>Attempt #{{ $run->id }}</h2><x-filament::badge color="gray">{{ $run->candidate_state ? \Illuminate\Support\Str::headline($run->candidate_state) : (\App\Models\ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status) }} · Not official</x-filament::badge></div>
        <p class="tala-run-totals"><x-filament::icon icon="heroicon-o-calendar-days" /><strong>{{ $assignmentCount }} meetings</strong><span>{{ $summary['conflicts'] }} assignments needing attention</span><span>{{ $summary['warnings'] }} warnings</span></p>
        <div class="tala-evidence-surface tala-run-check-summary">
            <div><x-filament::icon icon="heroicon-o-cpu-chip" /><div><span class="tala-work-footnote">Recorded solver outcome</span><strong>{{ $evidence['outcome'] }}</strong></div></div>
            <div><x-filament::icon :icon="$evidence['validated'] ? 'heroicon-o-shield-check' : 'heroicon-o-information-circle'" /><div><span class="tala-work-footnote">Independent validation</span><p>{{ $evidence['coverage'] }}</p><p class="tala-work-footnote">{{ $evidence['conflicts'] }}</p></div></div>
        </div>
        <p class="tala-work-footnote">{{ $evidence['meaning'] }}</p>
        @if ($run->status === \App\Models\ScheduleGenerationRun::StatusBlocked)<p class="tala-run-blocker" role="status">{{ $run->finalSolverFailure()['message'] ?? data_get($run->diagnostics, 'solver_result.summary.message', 'Review the validation findings before requesting a new draft.') }}</p>@endif
        <p>{{ $nextStep }}</p>
        <div class="tala-evidence-actions"><x-filament::button tag="a" href="#timetable-run-evidence" color="gray" icon="heroicon-o-clipboard-document-check">Review checks and findings</x-filament::button></div>
    @endif
</section>
