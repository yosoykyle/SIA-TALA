@php
    $ordinary = $reviewState === 'ordinary';
    $history = $accountingView === 'accounting-history';
@endphp
<div class="accounting-selected-record">
    <x-record-identity :name="$name" :number="\App\Actions\Finance\AccountingDisplay::learnerIdentifier($case)"
        :program="\App\Actions\Finance\AccountingDisplay::learnerProgram($case, '') ?: null"
        :initials="$initials" :photo-url="$student?->adminPhotoUrl()" class="tala-record-identity--compact">
        <x-slot:supporting><p>{{ $case->term?->label ?? 'Term unavailable' }}</p></x-slot:supporting>
    </x-record-identity>
    @if ($account || ($lifecycleStatus['reviewed'] ?? 0) > 0)
        <x-tala-navigation label="Student account section" stretch>
            <x-filament::tabs.item tag="button" type="button" wire:click="$parent.showAccountingSection('work')" :active="! $history" :aria-current="! $history ? 'page' : null">Current work</x-filament::tabs.item>
            <x-filament::tabs.item tag="button" type="button" wire:click="$parent.showAccountingSection('history')" :active="$history" :aria-current="$history ? 'page' : null">History</x-filament::tabs.item>
        </x-tala-navigation>
    @endif
    @if ($history)
        @include('filament.admin.enrollments.accounting-history')
    @else
        @foreach ($workSections as $section => $sectionData)
            <div class="accounting-current-task" wire:key="account-work-{{ $section }}">
                @include('filament.admin.enrollments.'.$section, $sectionData)
            </div>
        @endforeach
        @if ($account)
            <div class="accounting-account-context">
                @if ($ordinary)
                    <div class="accounting-workspace-position">
                        <dl>
                            <div><dt>{{ (float) $position['current_due'] > 0 ? 'Due now' : 'Current payment' }}</dt><dd>{{ (float) $position['current_due'] > 0 ? \App\Actions\Finance\AccountingDisplay::money($position['current_due']) : 'No payment due now' }}</dd><p class="accounting-workspace-status">{{ $requirement['state'] === 'Cleared' ? 'Enrollment payment satisfied' : 'Enrollment payment pending' }}</p></div>
                            <div><dt>Remaining term fees</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($position['remaining_balance']) }}</dd></div>
                        </dl>
                    </div>
                @endif
                @include('filament.admin.enrollments.accounting-financial-summary')
            </div>
            @php
                $canRecordOnsite = $this->getAction('recordOnsitePayment', isMounting: false)?->isVisible();
                $canRecordCoverage = $ordinary && $this->getAction('recordApprovedCoverage', isMounting: false)?->isVisible();
            @endphp
            @if ($canRecordOnsite || $canRecordCoverage)
                <div class="registrar-case-task-actions">
                    @if ($canRecordOnsite){{ $this->getAction('recordOnsitePayment', isMounting: false) }}@endif
                    @if ($canRecordCoverage){{ $this->getAction('recordApprovedCoverage', isMounting: false)->color('gray') }}@endif
                </div>
            @endif
        @endif
    @endif
    @if ($student && ($hasTranscriptRequests ?? false)
        && \App\Filament\Resources\TranscriptRequests\TranscriptRequestResource::canViewAny())
        <section class="accounting-document-context">
            <h2>TOR restrictions</h2>
            <p>Check Transcript of Records (TOR) requests for this student and record or lift a financial restriction if needed.</p>
            <x-filament::link icon="heroicon-o-shield-exclamation" :href="\App\Filament\Resources\TranscriptRequests\TranscriptRequestResource::getUrl('index', ['search' => $student->student_number])">Review TOR restrictions</x-filament::link>
        </section>
    @endif
</div>
