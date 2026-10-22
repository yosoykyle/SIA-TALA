@php
    $case = $getRecord();
    $stage = \App\Filament\Resources\Enrollments\Tables\EnrollmentsTable::stage($case);
    $lookup = in_array($getLivewire()->activeTab, ['all_cases', 'official_history', 'adjustments_drops'], true);
    $status = match ($stage) {
        'Waiting for learner' => 'Waiting for confirmation',
        'Source review required' => 'Requirements need review',
        'Placement required' => 'Classes need attention',
        'Ready to finalize' => 'Ready to make official',
        default => $stage,
    };
    $color = match ($stage) {
        'Officially enrolled' => 'success',
        'Ready to finalize' => 'info',
        'Placement required', 'Source review required' => 'warning',
        'Cancelled', 'Not enrolled' => 'danger',
        default => 'gray',
    };
    $action = $lookup ? 'Open record' : match ($stage) {
        'Prepare subjects' => 'Prepare subjects',
        'Placement required' => 'Review classes',
        'Source review required' => 'Review requirements',
        'Payment or fee review' => 'Review payment or fees',
        'Ready to finalize' => 'Review and finalize',
        default => 'Review enrollment',
    };
    $program = $case->currentProposalVersion?->curriculumVersion?->program?->name
        ?? $case->admissionApplication?->program?->name
        ?? $case->studentProfile?->program?->name
        ?? 'Program not assigned';
    $deadline = in_array($stage, ['Waiting for learner', 'Placement required'], true) ? $case->currentProposalVersion?->items->map(fn ($item) => $item->reservation?->deadline)->filter()->min() : null;
    $requirement = ! $lookup && $stage === 'Payment or fee review' ? app(\App\Actions\Finance\EnrollmentPaymentRequirementProjection::class)->forEnrollment($case) : null;
    $subjectCount = $case->currentProposalVersion?->items->count() ?? 0;
@endphp
<article class="registrar-enrollment-record">
    <div class="registrar-enrollment-person">
        <h2>{{ $case->credentialUser?->getFilamentName() ?? 'Identity unavailable' }}</h2>
        <p class="registrar-enrollment-identifier">{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference ?? 'Number not assigned' }}</p>
        <p class="registrar-enrollment-program">{{ $program }}</p>
        <p class="registrar-enrollment-term">{{ $case->term?->label ?? 'Term not assigned' }}</p>
    </div>
    <div class="registrar-enrollment-status">
        <x-filament::badge :color="$color">{{ $status }}</x-filament::badge>
        @if (! $lookup)
            @if ($stage === 'Placement required' && $deadline?->isPast())
                <p>Temporary seats expired · {{ $deadline->timezone(config('app.display_timezone'))->format('M j, g:i A') }}</p>
            @elseif ($stage === 'Waiting for learner' && $deadline)
                <p>Seats held until {{ $deadline->timezone(config('app.display_timezone'))->format('M j, g:i A') }}</p>
            @elseif ($requirement !== null)
                <p>{{ $requirement['balance'] === null ? 'Accounting needs to review the fee source.' : 'PHP '.number_format((float) $requirement['balance'], 2).' enrollment requirement remaining' }}</p>
            @elseif ($stage === 'Ready to finalize')
                <p>{{ $subjectCount }} confirmed {{ $subjectCount === 1 ? 'subject' : 'subjects' }} · {{ (float) $case->currentProposalVersion->items->sum('units_snapshot') }} units</p>
            @endif
        @endif
        <x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case])" icon="heroicon-m-arrow-right" icon-position="after">
            {{ $action }}<span class="sr-only"> for {{ $case->credentialUser?->getFilamentName() }}</span>
        </x-filament::link>
    </div>
</article>
