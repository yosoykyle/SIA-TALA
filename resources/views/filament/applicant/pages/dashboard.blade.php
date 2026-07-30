<x-filament-panels::page>
    @php
        $intake = $this->getIntake();
        $draftUploadCount = count($intake?->draft_document_references ?? []);
        $workflow = $intake ? $this->workflowSummary($intake) : null;
    @endphp

    @if (! $intake)
        <x-filament::section class="max-w-4xl mx-auto py-8">
            <div class="tala-empty-state">
                <div class="tala-empty-state__icon-shell">
                    <x-filament::icon
                        icon="heroicon-o-document-text"
                        class="tala-empty-state__icon"
                    />
                </div>

                <div class="tala-empty-state__copy">
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">
                        No active application
                    </h2>
                    <p class="max-w-md text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                        Start a new application only while Admissions is open. Earlier applications remain available in Application History.
                    </p>
                </div>

                <div class="tala-empty-state__actions">
                    @if ($this->admissionsAreOpen())
                        <x-filament::button
                            :href="\App\Filament\Applicant\Pages\Application::getUrl()"
                            tag="a"
                            icon="heroicon-m-document-text"
                            size="lg"
                        >
                            Start Application
                        </x-filament::button>
                    @else
                        <x-filament::callout color="info" icon="heroicon-m-clock">
                            <x-slot name="heading">Admissions are currently closed</x-slot>
                            <x-slot name="description">
                                Check the public TALA page for the next admission period or contact the Registrar.
                            </x-slot>
                        </x-filament::callout>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">
                    <div class="tala-section-heading">
                        <span>Application Status</span>
                        <x-filament::badge :color="$this->statusColor($intake->status)" size="lg">
                            {{ $this->statusLabel($intake->status) }}
                        </x-filament::badge>
                    </div>
                </x-slot>

                <dl class="tala-status-grid">
                    <div class="tala-status-grid__item">
                        <dt>Academic Term</dt>
                        <dd>{{ $intake->term?->label ?? 'Not assigned' }}</dd>
                    </div>
                    <div class="tala-status-grid__item">
                        <dt>Preferred Program</dt>
                        <dd>{{ $intake->program?->name ?? 'Not assigned' }}</dd>
                    </div>
                    <div class="tala-status-grid__item">
                        <dt>Admission Category</dt>
                        <dd>{{ str($intake->admission_category)->replace('_', ' ')->title() }}</dd>
                    </div>
                    <div class="tala-status-grid__item">
                        <dt>Submission Date</dt>
                        <dd>{{ \App\Support\DisplayDateTime::format($intake->submitted_at, 'F j, Y, g:i a', 'Not submitted') }}</dd>
                    </div>
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Next Step</x-slot>

                <div class="tala-guidance">
                    @if ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
                        <x-filament::callout color="info" icon="heroicon-m-pencil-square">
                            <x-slot name="heading">Draft saved</x-slot>
                            <x-slot name="description">
                                {{ $draftUploadCount }} {{ $draftUploadCount === 1 ? 'document is' : 'documents are' }} attached. Complete the remaining information, then submit the application for Registrar review.
                            </x-slot>
                        </x-filament::callout>
                        <div class="tala-action-block">
                            <x-filament::button
                                :href="\App\Filament\Applicant\Pages\Application::getUrl()"
                                tag="a"
                                icon="heroicon-m-pencil-square"
                            >
                                Continue Application
                            </x-filament::button>
                        </div>
                    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusPending)
                        <x-filament::callout color="warning" icon="heroicon-m-clock">
                            <x-slot name="heading">Registrar review in progress</x-slot>
                            <x-slot name="description">
                                Check Requirements for each submission method and any item that still needs attention.
                            </x-slot>
                        </x-filament::callout>
                    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
                        <x-filament::callout color="danger" icon="heroicon-m-exclamation-triangle">
                            <x-slot name="heading">A correction is required</x-slot>
                            <x-slot name="description">
                                Open Requirements, read the Registrar instruction, and replace each rejected digital item.
                            </x-slot>
                        </x-filament::callout>
                    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusForEvaluation)
                        <x-filament::callout color="info" icon="heroicon-m-magnifying-glass">
                            <x-slot name="heading">Credential evaluation in progress</x-slot>
                            <x-slot name="description">
                                The Registrar is evaluating the application before approval and student handover.
                            </x-slot>
                        </x-filament::callout>
                    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusApproved)
                        <x-filament::callout color="success" icon="heroicon-m-check-circle">
                            <x-slot name="heading">Application approved</x-slot>
                            <x-slot name="description">
                                Student Hub access becomes available after the Registrar completes student handover.
                            </x-slot>
                        </x-filament::callout>
                    @endif

                    @if ($intake->status !== \App\Models\ApplicantIntake::StatusDraft)
                        <div class="tala-action-block">
                            <x-filament::button
                                :href="\App\Filament\Applicant\Pages\Requirements::getUrl()"
                                tag="a"
                                icon="heroicon-m-clipboard-document-check"
                            >
                                Review Requirements
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>

            @if ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
                <x-filament::section>
                    <x-slot name="heading">Draft progress</x-slot>

                    <div class="tala-draft-progress">
                        <p>
                            <strong>{{ $draftUploadCount }} {{ $draftUploadCount === 1 ? 'document' : 'documents' }} attached to this draft.</strong>
                            You can replace or add files before submission.
                        </p>
                        <p>The Registrar checklist and review history are created after you submit the application.</p>
                    </div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <x-slot name="heading">Requirement Readiness</x-slot>
                    <x-slot name="description">
                        This summary shows progress only. Requirements owns the instructions, latest evidence state, feedback, and permitted correction.
                    </x-slot>

                    @if ($workflow['requirement_count'] === 0)
                        <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                            <x-slot name="heading">Checklist unavailable</x-slot>
                            <x-slot name="description">
                                Contact the Registrar to confirm the requirements configured for this application.
                            </x-slot>
                        </x-filament::callout>
                    @else
                        <p class="tala-requirement-summary">{{ $workflow['requirements_summary'] }}</p>
                        <dl class="tala-status-grid">
                            <div class="tala-status-grid__item">
                                <dt>Resolved</dt>
                                <dd>{{ $workflow['resolved_requirement_count'] }} of {{ $workflow['requirement_count'] }}</dd>
                            </div>
                            <div class="tala-status-grid__item">
                                <dt>Outstanding</dt>
                                <dd>{{ $workflow['outstanding_requirement_count'] }}</dd>
                            </div>
                            <div class="tala-status-grid__item">
                                <dt>Blocks Handover</dt>
                                <dd>{{ $workflow['handover_blocker_count'] }}</dd>
                            </div>
                        </dl>
                    @endif
                </x-filament::section>
            @endif
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Application History</x-slot>
        <x-slot name="description">
            Review earlier and current admission records. Sensitive withdrawal details appear only after you select View.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
