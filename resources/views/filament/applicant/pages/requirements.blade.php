<x-filament-panels::page>
    @php($application = $this->application())

    @if (! $application || ! $application->currentSubmissionVersion)
        <x-filament::section>
            <x-slot name="heading">Requirements are not available yet</x-slot>
            <x-slot name="description">Submit your application to see its requirements and review results.</x-slot>
            <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application?->id])" tag="a" icon="heroicon-m-document-text">
                Open application
            </x-filament::button>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Application requirements</x-slot>
            <x-slot name="description">
                <x-application-reference :value="$application->application_reference" /> · Requirements version {{ $application->currentSubmissionVersion->requirementSet->version }}
            </x-slot>

            @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->first())
            @if ($activeCorrection)
                <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                    <x-slot name="heading">{{ $activeCorrection->isOverdue() ? 'Correction overdue' : 'Correction due' }} {{ $activeCorrection->due_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} (Asia/Manila)</x-slot>
                    <x-slot name="description">{{ $activeCorrection->applicant_instruction }} Only the named fields or evidence reopen. An overdue request remains editable and resubmittable.</x-slot>
                </x-filament::callout>
                <div class="tala-action-block">
                    <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application?->id])" tag="a" icon="heroicon-m-pencil-square">
                        Respond to correction
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        {{ $this->table }}

        @php($projection = app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->forApplication($application))
        @php($clearance = $application->enrollmentClearances->firstWhere('id', $projection['clearance_id']))
        <x-filament::section>
            <x-slot name="heading">Registrar enrollment clearance</x-slot>
            <x-slot name="description">Paper credentials are received and checked outside TALA. The Registrar records one clearance after admission.</x-slot>
            <x-filament::badge :color="$projection['ready'] ? 'success' : 'warning'">
                {{ $application->application_state !== \App\Models\AdmissionApplication::StateAdmitted ? 'Available after admission' : ($projection['ready'] ? 'Cleared' : ($projection['clearance_result'] === 'ActionNeeded' ? 'Action needed' : 'Awaiting current Registrar clearance')) }}
            </x-filament::badge>
            @if ($clearance)
                <p class="mt-4 text-sm">Recorded by {{ $clearance->recorder?->getFilamentName() ?? 'Registrar' }} on {{ $clearance->recorded_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}.</p>
            @endif
            @foreach ($projection['blockers'] as $blocker)
                @if ($application->application_state === \App\Models\AdmissionApplication::StateAdmitted && $blocker['source'] === 'Registrar enrollment clearance')
                    <p class="mt-4">{{ $blocker['recovery'] }}</p>
                @endif
            @endforeach
        </x-filament::section>

        @if ($application->credentialResults->isNotEmpty())
            <x-filament::section collapsed collapsible>
                <x-slot name="heading">Retained credential history</x-slot>
                @foreach ($application->credentialResults->sortByDesc('recorded_at') as $result)
                    <p>{{ $result->requirement->label }} — {{ str($result->result)->headline() }} — {{ $result->recorded_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</p>
                @endforeach
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
