@php
    $expiredReservation = $proposal?->items->contains(fn ($item): bool => $item->reservation?->deadline?->isPast() ?? false) ?? false;
@endphp
<div class="student-registration learner-status-page">
    <div class="student-registration-heading">
        <div>
            <h2>{{ $proposal?->curriculumVersion?->program?->name ?? $enrollment->studentProfile?->program?->name ?? $enrollment->admissionApplication?->program?->name ?? 'Program unavailable' }}</h2>
            <p>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }} · School year {{ $enrollment->term?->academicYear?->label ?? 'unavailable' }}</p>
        </div>
    </div>
    <section class="student-academic-notice" aria-labelledby="recovery-heading" role="status">
        <x-filament::badge color="warning">Not official yet</x-filament::badge>
        <h2 id="recovery-heading">The Registrar needs to review your classes</h2>
        <p class="student-registration-help">{{ $expiredReservation ? 'This enrollment is not official yet. Your temporary seat reservation expired.' : 'This enrollment is not official yet. The Registrar needs to confirm an available class placement.' }}</p>
        <h3 class="font-semibold text-base">What happens next</h3>
        <p>Contact the Registrar to review available classes. Former seats are not guaranteed. If your subjects change, you will review and confirm them again.</p>
        <div class="learner-status-actions">
            <x-filament::button wire:click="$refresh" color="gray" icon="heroicon-o-arrow-path">Refresh status</x-filament::button>
            @if ($isStudent)<x-filament::link :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::link>@endif
        </div>
    </section>
    <details class="learner-disclosure"><summary>Your records stay on file</summary><div>
        <p>Subjects were confirmed for the earlier proposal. Recorded payments and coverage remain on your account.</p>
        <p>The Registrar checks current placement before official enrollment. No Certificate of Registration has been issued.</p>
    </div></details>
    @if ($proposal && $proposal->items->isNotEmpty())
        <details class="learner-disclosure">
            <summary>Previously proposed subjects and schedule</summary>
            <div class="space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">This earlier proposal is shown for reference; it does not reserve a current seat.</p>
                @include('filament.student.pages.enrollment-subjects', ['preparing' => false])
                @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
                    <x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" />
                @endif
            </div>
        </details>
    @endif
    <x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
</div>
