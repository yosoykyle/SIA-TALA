<x-filament-panels::page>
    @php($application = $this->application())

    @if (! $application || ! $application->currentSubmissionVersion)
        <x-servitech-empty-state
            illustrated
            heading="Requirements are not available yet"
            description="Submit your application to see its requirements and review results."
        >
            <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application?->id])" tag="a" icon="heroicon-m-document-text">
                Open application
            </x-filament::button>
        </x-servitech-empty-state>
    @else
        <div>
            <x-application-reference :value="$application->application_reference" />
        </div>
            @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->first())
            @if ($activeCorrection)
                @include('filament.applicant.components.correction-request')
                <div class="tala-action-block">
                    <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application?->id])" tag="a" icon="heroicon-m-pencil-square">
                        Respond to correction
                    </x-filament::button>
                </div>
            @endif
        {{ $this->table }}

        @php($onSiteItems = $this->onSiteItems($application))
        @if ($onSiteItems !== [])
            <x-filament::section icon="heroicon-o-building-office-2" icon-color="primary">
                <x-slot name="heading">To be passed on-site</x-slot>
                <x-slot name="description">Bring these official papers to the Registrar's office. The Registrar confirms them in person.</x-slot>
                <ul class="tala-onsite-list">
                    @foreach ($onSiteItems as $item)
                        <li class="tala-onsite-item">
                            <div class="tala-onsite-item__head">
                                <span class="tala-onsite-item__label">{{ $item['label'] }}</span>
                                <x-filament::badge size="sm" :color="$item['color']">{{ $item['state'] }}</x-filament::badge>
                            </div>
                            <p class="tala-onsite-item__due">{{ $item['due'] }}</p>
                            @if (filled($item['instructions']))
                                <p class="tala-onsite-item__instructions">{{ $item['instructions'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        @php($projection = app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->forApplication($application))
        @php($clearance = $application->enrollmentClearances->firstWhere('id', $projection['clearance_id']))
        @if ($application->application_state === \App\Models\AdmissionApplication::StateAdmitted)
        <x-filament::section>
            <x-slot name="heading">Registrar enrollment clearance</x-slot>
            <x-slot name="description">The Registrar checks your physical credentials and records your clearance for enrollment.</x-slot>
            <x-filament::badge :color="$projection['clearance_result'] === 'Cleared' ? 'success' : 'warning'">
                {{ $application->application_state !== \App\Models\AdmissionApplication::StateAdmitted ? 'Available after admission' : ($projection['clearance_result'] === 'Cleared' ? 'Cleared' : ($projection['clearance_result'] === 'ActionNeeded' ? 'Action needed' : 'Awaiting current Registrar clearance')) }}
            </x-filament::badge>
            @if ($clearance)
                <p class="mt-4 text-sm">Recorded by {{ $clearance->recorder?->getFilamentName() ?? 'Registrar' }} on {{ $clearance->recorded_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}.</p>
            @endif
            @foreach ($projection['blockers'] as $blocker)
                @if ($application->application_state === \App\Models\AdmissionApplication::StateAdmitted && $blocker['source'] === 'Registrar enrollment clearance')
                    <p class="mt-4">{{ $blocker['recovery'] }}</p>
                @endif
            @endforeach
            @if ($application->application_state === \App\Models\AdmissionApplication::StateAdmitted)
                <p class="mt-4">{{ $projection['ready'] ? app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->enrollmentNextAction($application) : 'Enrollment readiness still needs current admissions checks. Contact the Registrar for the next instruction.' }}</p>
            @endif
        </x-filament::section>
        @else
            <p class="text-sm">@if ($application->application_state === \App\Models\AdmissionApplication::StateSubmitted || $application->application_state === \App\Models\AdmissionApplication::StateActionNeeded)Physical credentials are checked after admission. @endif Your receipt retains the instructions issued with your submission.</p>
        @endif

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
