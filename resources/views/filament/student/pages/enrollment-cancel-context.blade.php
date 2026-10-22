@php
    $program = $proposal?->curriculumVersion?->program?->name ?? $enrollment?->admissionApplication?->program?->name ?? $enrollment?->studentProfile?->program?->name;
@endphp
<div class="learner-cancel-context">
    <p><strong>{{ $program ?? 'Program unavailable' }}</strong> · {{ $enrollment?->term?->label ?? 'Term unavailable' }}</p>
    <ul>
        <li>{{ $proposal && $proposal->items->isNotEmpty() ? 'Temporary seats for '.$proposal->items->count().' '.str('subject')->plural($proposal->items->count()).' are released now.' : 'Any temporary seats are released now.' }}</li>
        <li>Your enrollment, payments and account history stay on record.</li>
        <li>Only the Registrar can reopen it.</li>
    </ul>
</div>
