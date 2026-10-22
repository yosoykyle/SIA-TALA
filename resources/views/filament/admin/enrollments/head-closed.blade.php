@assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
<div class="head-enrollment-detail">
    <div class="head-enrollment-context"><p>{{ \App\Filament\Resources\Enrollments\Tables\EnrollmentsTable::headProgram($case)?->name ?? 'Program unavailable' }}</p><div><x-filament::badge color="gray">{{ $outcome }}</x-filament::badge><p>Read-only · Registrar maintains this record</p></div></div>
    <section class="head-enrollment-closed-message" aria-labelledby="head-closed-enrollment">
        <x-heroicon-o-information-circle aria-hidden="true" /><div><h2 id="head-closed-enrollment">{{ $knownOutcome ? 'Closed enrollment' : 'Enrollment outcome needs review' }}</h2><p>{{ $knownOutcome ? 'This case is not an active enrollment task. Its recorded activity and source history remain available below.' : 'The recorded outcome is unavailable. Registrar must review the source; no current enrollment state is inferred.' }}</p>@if (filled($case->status_reason))<p>Recorded reason: {{ $case->status_reason }}</p>@endif</div>
    </section>
    <section class="head-enrollment-panel" aria-labelledby="head-closed-activity"><h2 id="head-closed-activity">Recorded activity</h2><p class="head-enrollment-note">Most recent first · Asia/Manila</p>@include('filament.admin.enrollments.head-history')</section>
    <details class="head-enrollment-evidence"><summary>Retained source details</summary><dl class="head-enrollment-source-grid">
        <div><dt>Registration reference</dt><dd>{{ $case->case_reference }}</dd></div><div><dt>Academic selection basis</dt><dd>{{ filled($case->selection_basis) ? \Illuminate\Support\Str::headline($case->selection_basis) : 'Not recorded' }}</dd></div>
        <div><dt>Last selected proposal</dt><dd>{{ $case->currentProposalVersion ? 'Version '.$case->currentProposalVersion->version.' · '.$case->currentProposalVersion->state : 'Not recorded' }}</dd></div>
        <div><dt>Current clearance source</dt><dd>{{ match ($requirement['state']) { 'Cleared' => 'Cleared', 'ActionNeeded' => 'Accounting review needed', default => 'Unavailable' } }} · Accounting<small>{{ $requirement['assessment_id'] !== null ? 'Assessment '.$requirement['assessment_id'] : 'Current assessment unavailable' }}</small></dd></div>
        <div><dt>Retained certificate versions</dt><dd>{{ $case->corVersions->isNotEmpty() ? $case->corVersions->sortByDesc('version')->map(fn ($version) => 'COR v'.$version->version)->implode(', ') : 'No issued COR recorded' }}</dd></div>
        <div><dt>Record updated</dt><dd>{{ $case->updated_at?->timezone('Asia/Manila')->format('j M Y, g:i A') ?? 'Time unavailable' }}</dd></div>
    </dl></details>
</div>
