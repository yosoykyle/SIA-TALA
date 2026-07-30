<x-filament-panels::page>
    @php
        $intake = $this->intake();
        $workflow = $intake ? $this->workflowSummary($intake) : null;
    @endphp

    @if (! $intake)
        <x-filament::section>
            <x-slot name="heading">Admission Requirements</x-slot>
            <x-slot name="description">
                This page owns the instructions, evidence state, and Registrar feedback connected to your application.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-information-circle">
                <x-slot name="heading">No application yet</x-slot>
                <x-slot name="description">
                    Start your application first. Your requirement checklist will appear here after submission.
                </x-slot>
            </x-filament::callout>
            <div class="tala-action-block">
                <x-filament::button
                    :href="\App\Filament\Applicant\Pages\Application::getUrl()"
                    tag="a"
                    icon="heroicon-m-document-text"
                >
                    Start Application
                </x-filament::button>
            </div>
        </x-filament::section>
    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
        <x-filament::section>
            <x-slot name="heading">Your application is still a draft</x-slot>
            <x-slot name="description">
                Complete your information and configured digital uploads before sending the application to the Registrar.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-pencil-square">
                <x-slot name="heading">No Registrar checklist yet</x-slot>
                <x-slot name="description">
                    Review status and Registrar feedback appear here after submission.
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
        </x-filament::section>
    @elseif ($intake->status === \App\Models\ApplicantIntake::StatusWithdrawn && $intake->submitted_at === null)
        <x-filament::section>
            <x-slot name="heading">Withdrawn before submission</x-slot>
            <x-slot name="description">
                This draft closed before it reached the Registrar, so no checklist was created.
            </x-slot>

            <x-filament::callout color="gray" icon="heroicon-m-archive-box-x-mark">
                <x-slot name="heading">Application closed</x-slot>
                <x-slot name="description">
                    @if ($intake->archived_at)
                        Withdrawn on {{ \App\Support\DisplayDateTime::format($intake->archived_at, 'F j, Y \a\t g:i A') }}.
                    @endif
                    Reason: {{ $intake->withdrawalActivity?->properties?->get('reason') ?? 'No reason was recorded.' }}
                    Contact the Registrar if you need another admission intake.
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @elseif ($intake->checklistItems->isEmpty())
        <x-filament::section>
            <x-slot name="heading">Registrar checklist unavailable</x-slot>
            <x-slot name="description">
                Your application was submitted, but its requirement records are not available.
            </x-slot>

            <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                <x-slot name="heading">Contact the Registrar</x-slot>
                <x-slot name="description">
                    Ask the Registrar to confirm the requirements configured for this application.
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Your Requirements</x-slot>
            <x-slot name="description">
                Review how to provide each item, its workflow effect, latest evidence state, and the Registrar instruction.
            </x-slot>

            <p class="tala-requirement-summary">{{ $workflow['requirements_summary'] }}</p>

            <div class="tala-requirements-list">
                @foreach ($intake->checklistItems as $item)
                    @php
                        $latestEvidence = $item->documentEvidence->sortByDesc('uploaded_at')->first();
                        $submissionInstruction = match ($item->evidence_method) {
                            \App\Models\ChecklistItem::EvidenceMethodDigitalUpload => 'Upload Online',
                            \App\Models\ChecklistItem::EvidenceMethodPhysicalCopy => 'Bring to the Registrar',
                            \App\Models\ChecklistItem::EvidenceMethodMetadataOnly => 'Recorded by the Registrar',
                            default => \App\Models\ChecklistItem::evidenceMethodLabel($item->evidence_method),
                        };
                        $latestEvidenceLabel = match ($item->evidence_method) {
                            \App\Models\ChecklistItem::EvidenceMethodDigitalUpload => $latestEvidence
                                ? 'File submitted '.\App\Support\DisplayDateTime::format($latestEvidence->uploaded_at, '\o\n M j, Y', '')
                                : 'No file submitted',
                            \App\Models\ChecklistItem::EvidenceMethodPhysicalCopy => $item->status === \App\Models\ChecklistItem::StatusReceivedPhysical
                                ? 'Physical copy recorded as received'
                                : 'No online file required',
                            \App\Models\ChecklistItem::EvidenceMethodMetadataOnly => 'No file required; the Registrar records the result',
                            default => 'Not applicable',
                        };
                    @endphp

                    <article class="tala-requirement-card" wire:key="requirement-{{ $item->id }}">
                        <header class="tala-requirement-card__header">
                            <h3>{{ \App\Models\ChecklistItem::requirementTypeLabel($item->requirement_type) }}</h3>
                            <x-filament::badge :color="match ($item->status) {
                                \App\Models\ChecklistItem::StatusAccepted,
                                \App\Models\ChecklistItem::StatusWaived,
                                \App\Models\ChecklistItem::StatusUndertakingApproved => 'success',
                                \App\Models\ChecklistItem::StatusRejected => 'danger',
                                \App\Models\ChecklistItem::StatusReceivedDigital,
                                \App\Models\ChecklistItem::StatusReceivedPhysical => 'info',
                                default => 'warning',
                            }">
                                {{ \App\Models\ChecklistItem::statusLabel($item->status) }}
                            </x-filament::badge>
                        </header>

                        <dl class="tala-requirement-card__facts">
                            <div>
                                <dt>How to submit</dt>
                                <dd>{{ $submissionInstruction }}</dd>
                            </div>
                            <div>
                                <dt>Workflow effect</dt>
                                <dd>{{ \App\Models\ChecklistItem::blockingLevelLabel($item->blocking_level) }}</dd>
                            </div>
                            <div>
                                <dt>Verification</dt>
                                <dd>{{ \App\Models\ChecklistItem::verificationStatusLabel($item->verification_status) }}</dd>
                            </div>
                            <div>
                                <dt>Latest evidence</dt>
                                <dd>{{ $latestEvidenceLabel }}</dd>
                            </div>
                        </dl>

                        <div class="tala-requirement-card__instruction">
                            <h4>Registrar instruction</h4>
                            <p>{{ $item->waiver_reason ?? $item->undertaking_terms ?? 'No additional instruction.' }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </x-filament::section>

        @if ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
            <x-filament::section>
                <x-slot name="heading">Submit Corrected Evidence</x-slot>
                <x-slot name="description">
                    Select a rejected digital requirement and upload its corrected replacement. Earlier versions remain in the authorized audit history.
                </x-slot>

                <form wire:submit="replaceEvidence" class="space-y-6">
                    {{ $this->form }}

                    <div class="tala-action-block">
                        <x-filament::button
                            type="submit"
                            icon="heroicon-m-arrow-up-tray"
                            wire:loading.attr="disabled"
                            wire:target="replaceEvidence"
                        >
                            Submit Corrected Evidence
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
