<x-filament-panels::page>
    @php($application = $this->application())

    @if (! $application || ! $application->currentSubmissionVersion)
        <x-filament::section>
            <x-slot name="heading">Requirements are not available yet</x-slot>
            <x-slot name="description">A version-bound Requirement Set appears after the first Application submission.</x-slot>
            <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl()" tag="a" icon="heroicon-m-document-text">
                Open application
            </x-filament::button>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $application->application_reference }} requirements</x-slot>
            <x-slot name="description">
                Requirement Set version {{ $application->currentSubmissionVersion->requirementSet->version }}. Preliminary review and official credential verification are deliberately separate.
            </x-slot>

            @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->first())
            @if ($activeCorrection)
                <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                    <x-slot name="heading">{{ $activeCorrection->isOverdue() ? 'Correction overdue' : 'Scoped correction due' }} {{ $activeCorrection->due_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</x-slot>
                    <x-slot name="description">{{ $activeCorrection->applicant_instruction }} Only the named fields or evidence reopen. An overdue request remains editable and resubmittable.</x-slot>
                </x-filament::callout>
                <div class="tala-action-block">
                    <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl()" tag="a" icon="heroicon-m-pencil-square">
                        Respond to correction
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Preliminary digital review</x-slot>
            <div class="tala-requirements-list">
                @forelse ($this->preliminaryRows($application) as $row)
                    <article class="tala-requirement-card">
                        <header class="tala-requirement-card__header">
                            <h3>{{ $row['requirement']->label }}</h3>
                            <x-filament::badge :color="$this->resultColor($row['result'])">
                                {{ $this->resultLabel($row['result']) }}
                            </x-filament::badge>
                        </header>
                        <dl class="tala-requirement-card__facts">
                            <div><dt>Purpose</dt><dd>{{ $row['requirement']->purpose }}</dd></div>
                            <div><dt>Due stage</dt><dd>{{ str($row['requirement']->due_stage)->headline() }}</dd></div>
                            <div><dt>Submission method</dt><dd>Private digital preliminary evidence</dd></div>
                            <div><dt>Last update</dt><dd>{{ $row['updated_at']?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') ?? 'No evidence submitted' }}</dd></div>
                        </dl>
                        <p><strong>Registrar instruction:</strong> {{ $row['instruction'] }}</p>
                        <p><strong>Permitted action:</strong> {{ $row['action'] }}</p>
                        @if ($row['evidence'])
                            <x-filament::button
                                :href="route('admissions.evidence.download', ['evidence' => $row['evidence']])"
                                tag="a"
                                color="gray"
                                icon="heroicon-m-arrow-down-tray"
                            >
                                Download latest private evidence
                            </x-filament::button>
                        @endif
                    </article>
                @empty
                    <p>No preliminary digital evidence is required by this version.</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Official credential verification</x-slot>
            <div class="tala-requirements-list">
                @forelse ($this->officialRows($application) as $row)
                    <article class="tala-requirement-card">
                        <header class="tala-requirement-card__header">
                            <h3>{{ $row['requirement']->label }}</h3>
                            <x-filament::badge :color="$this->resultColor($row['result'])">
                                {{ $this->resultLabel($row['result']) }}
                            </x-filament::badge>
                        </header>
                        <dl class="tala-requirement-card__facts">
                            <div><dt>Purpose</dt><dd>{{ $row['requirement']->purpose }}</dd></div>
                            <div><dt>Due stage</dt><dd>{{ str($row['requirement']->due_stage)->headline() }}</dd></div>
                            <div><dt>Official method</dt><dd>{{ str($row['requirement']->official_submission_method)->headline() }}</dd></div>
                            <div><dt>Last update</dt><dd>{{ $row['updated_at']?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') ?? 'Not yet recorded' }}</dd></div>
                        </dl>
                        <p><strong>Registrar instruction:</strong> {{ $row['instruction'] }}</p>
                        <p><strong>Permitted action:</strong> {{ $row['action'] }}</p>
                    </article>
                @empty
                    <p>No official credential row is available for this Requirement Set.</p>
                @endforelse
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
