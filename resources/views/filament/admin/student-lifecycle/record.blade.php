@php
    $snapshot = (array) $effects;
    $effectKeys = ['course_enrollment_ids' => 'Affected subjects', 'binding_ids' => 'Schedule assignments released', 'reservation_ids' => 'Seat reservations released'];
    $hasAdditionalEffects = collect([...array_keys($effectKeys), 'active_hold_count', 'cor_available_after', 'master_schedule_changes', 'affected_subjects'])->contains(fn ($key) => array_key_exists($key, $snapshot)) || filled(data_get($snapshot, 'finance_effect.message')) || ($shift && filled(data_get($snapshot, 'profile_status_after')));
@endphp
@if ($snapshot === [])
    <p class="tala-lifecycle-inspector-note">The original effect snapshot is unavailable. Current student facts are not substituted for historical evidence.</p>
@elseif ($hasAdditionalEffects)
    <section class="tala-lifecycle-inspector-section" aria-labelledby="lifecycle-evidence-heading">
        <header><h3 id="lifecycle-evidence-heading">{{ $applicationAudit ? 'Application effects' : 'Original effects' }}</h3></header>
        <dl class="tala-record-facts tala-lifecycle-evidence-facts">
            @foreach ($effectKeys as $key => $label)
                @if (array_key_exists($key, $snapshot))<div><dt>{{ $label }}</dt><dd>{{ count((array) $snapshot[$key]) }}</dd></div>@endif
            @endforeach
            @if (array_key_exists('active_hold_count', $snapshot))<div><dt>Existing restrictions retained</dt><dd>{{ $snapshot['active_hold_count'] }}</dd></div>@endif
            @if ($shift && ($status = data_get($snapshot, 'profile_status_after')))<div><dt>Student status after recording</dt><dd>{{ \App\Models\StudentProfile::lifecycleStatusLabel($status) }}</dd></div>@endif
            @if (array_key_exists('cor_available_after', $snapshot))<div><dt>Registration certificate after {{ $applicationAudit ? 'application' : 'recording' }}</dt><dd>{{ $snapshot['cor_available_after'] ? 'Available' : 'Unavailable' }}</dd></div>@endif
            @if (array_key_exists('master_schedule_changes', $snapshot))<div><dt>Published master schedule</dt><dd>{{ $snapshot['master_schedule_changes'] === 0 ? 'Unchanged' : $snapshot['master_schedule_changes'].' recorded changes' }}</dd></div>@endif
        </dl>
        @if ($subjects = data_get($snapshot, 'affected_subjects'))<ul class="tala-lifecycle-evidence-subjects">@foreach ($subjects as $subject)<li>{{ collect([$subject['code'] ?? null, $subject['title'] ?? null])->filter()->implode(' · ') }}</li>@endforeach</ul>@endif
        @if ($finance = data_get($snapshot, 'finance_effect.message'))<p class="tala-lifecycle-inspector-note">{{ $finance }}</p>@endif
    </section>
@endif
<section class="tala-lifecycle-inspector-section tala-lifecycle-recording" aria-labelledby="lifecycle-recording-heading">
    <header><h3 id="lifecycle-recording-heading">Recording details</h3></header>
    <dl class="tala-record-facts">
        <div><dt>Recorded by</dt><dd>{{ $change->recorder?->name ?? 'Not recorded' }}</dd></div>
        <div><dt>Recorded at</dt><dd>{{ $change->created_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') ?? 'Not recorded' }}@if ($change->created_at)<small>Asia/Manila</small>@endif</dd></div>
        @if ($applicationAudit)
            <div><dt>Applied by</dt><dd>{{ $applicationAudit->causer?->name ?? 'Not recorded' }}</dd></div>
            <div><dt>Applied at</dt><dd>{{ $applicationAudit->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}<small>Asia/Manila</small></dd></div>
        @endif
        @if ($change->decided_on)<div><dt>Approval date</dt><dd>{{ $change->decided_on->format('M j, Y') }}</dd></div>@endif
        @if ($change->requested_on)<div><dt>Request date</dt><dd>{{ $change->requested_on->format('M j, Y') }}</dd></div>@endif
        @foreach (['authority' => 'Approval reference', 'private_source_reference' => 'Office reference', 'late_exception_authority' => 'Late exception authority', 'late_exception_reason' => 'Late exception reason'] as $key => $label)
            @if (filled($change->{$key}))<div class="tala-record-reference"><dt>{{ $label }}</dt><dd>{{ $change->{$key} }}</dd></div>@endif
        @endforeach
    </dl>
</section>
@if ($shift)
    <section class="tala-lifecycle-inspector-section tala-lifecycle-record-credits" aria-label="Program shift credit checklist">
        <header><h3>Program shift credits <span>{{ $change->programShiftCredits->count() }}</span></h3></header>
        @if ($change->programShiftCredits->isEmpty())
            <p class="tala-lifecycle-inspector-note">No credit entries were recorded for this approval. This does not establish credit evaluation or enrollment readiness.</p>
        @else
            <div class="tala-lifecycle-credit-list">
                @foreach ($change->programShiftCredits as $credit)
                    <article><div><strong>{{ $credit->curriculumEntry?->courseSpecification?->course?->code }} · {{ $credit->curriculumEntry?->courseSpecification?->title ?? 'Target subject unavailable' }}</strong><p>{{ str($credit->treatment)->lower()->headline() }}@if ($credit->sourceCourse) · Source: {{ $credit->sourceCourse->code }}@endif @if ($credit->numeric_grade !== null) · {{ $credit->numeric_grade }}@endif</p></div><x-filament::badge color="gray">{{ str($credit->state)->lower()->headline() }}</x-filament::badge></article>
                @endforeach
            </div>
        @endif
    </section>
@endif
