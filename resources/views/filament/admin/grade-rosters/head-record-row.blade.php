@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php($roster = $getRecord())
<article class="academic-roster-row">
    <div class="academic-roster-identity">
        <h2>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'Course title unavailable' }}</h2>
        <p>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} · {{ $roster->section?->code }}</p>
        <p>{{ $roster->termOffering?->term?->label }}</p>
    </div>
    <dl class="academic-roster-figures">
        <div><dt>Designated faculty</dt><dd>{{ $roster->teachingAssignment?->faculty?->getFilamentName() ?? $roster->faculty?->getFilamentName() ?? 'Not recorded' }}</dd></div>
        <div><dt>Roster members</dt><dd>{{ number_format($roster->current_members_count) }}</dd></div>
    </dl>
    <span class="academic-roster-state">{{ match ($roster->state) { \App\Models\GradeRoster::StateDraft => 'Draft', \App\Models\GradeRoster::StateSubmitted => 'Submitted', \App\Models\GradeRoster::StateReturned => 'Returned', \App\Models\GradeRoster::StateReleased => 'Posted and released', default => 'Late / not submitted' } }}</span>
    <a class="academic-roster-open" href="{{ \App\Filament\Resources\GradeRosters\GradeRosterResource::getUrl('view', ['record' => $roster, 'oversightTermId' => $getLivewire()->oversightTermId]) }}" aria-label="Open roster for {{ $roster->section?->code }}, {{ $roster->termOffering?->term?->label }}">Open roster <span aria-hidden="true">→</span></a>
</article>
