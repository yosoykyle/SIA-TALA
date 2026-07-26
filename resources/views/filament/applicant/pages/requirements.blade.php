<x-filament-panels::page>
    @php($intake = $this->intake())

    @if (! $intake)
        <x-filament::section>
            <x-slot name="heading">Admission Requirements</x-slot>
            <x-slot name="description">
                This page tracks the documents and Registrar feedback connected to your application.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-information-circle">
                <x-slot name="heading">No application yet</x-slot>
                <x-slot name="description">
                    Start your application first. Your requirement checklist will appear here after you save or submit it.
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
                Complete your details and document uploads before sending the application to the Registrar.
            </x-slot>

            <x-filament::callout color="info" icon="heroicon-m-pencil-square">
                <x-slot name="heading">No Registrar checklist yet</x-slot>
                <x-slot name="description">
                    The checklist, review status, and Registrar feedback appear here only after you submit the application.
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
                This draft was closed before it reached the Registrar for review.
            </x-slot>

            <x-filament::callout color="gray" icon="heroicon-m-archive-box-x-mark">
                <x-slot name="heading">No Registrar checklist was created</x-slot>
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
                Your application has been submitted, but its requirement checklist is not available.
            </x-slot>

            <x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
                <x-slot name="heading">Contact the Registrar</x-slot>
                <x-slot name="description">
                    Ask the Registrar's Office to confirm that the admission requirements were configured for your application.
                </x-slot>
            </x-filament::callout>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Requirement Status and Instructions</x-slot>
            <x-slot name="description">
                Review every required item and the Registrar's latest feedback. A rejected digital file can be replaced only while your application shows Action Required.
            </x-slot>

            <div class="tala-table-scroll">
                <table class="tala-data-table">
                    <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>How to Submit</th>
                            <th>Workflow Effect</th>
                            <th>Status</th>
                            <th>Registrar Feedback / Instruction</th>
                            <th>Latest File</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($intake->checklistItems as $item)
                            @php($latestEvidence = $item->documentEvidence->sortByDesc('uploaded_at')->first())
                            <tr>
                                <td class="tala-data-table__primary">
                                    {{ \App\Models\ChecklistItem::requirementTypeLabel($item->requirement_type) }}
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ match ($item->evidence_method) {
                                        \App\Models\ChecklistItem::EvidenceMethodDigitalUpload => 'Upload Online',
                                        \App\Models\ChecklistItem::EvidenceMethodPhysicalCopy => 'Bring to the Registrar',
                                        \App\Models\ChecklistItem::EvidenceMethodMetadataOnly => 'Recorded by the Registrar',
                                        default => \App\Models\ChecklistItem::evidenceMethodLabel($item->evidence_method),
                                    } }}
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ \App\Models\ChecklistItem::blockingLevelLabel($item->blocking_level) }}
                                </td>
                                <td>
                                    <x-filament::badge :color="match ($item->status) {
                                        \App\Models\ChecklistItem::StatusAccepted => 'success',
                                        \App\Models\ChecklistItem::StatusRejected => 'danger',
                                        \App\Models\ChecklistItem::StatusReceivedDigital, \App\Models\ChecklistItem::StatusReceivedPhysical => 'info',
                                        default => 'warning',
                                    }">
                                        {{ \App\Models\ChecklistItem::statusLabel($item->status) }}
                                    </x-filament::badge>
                                </td>
                                <td class="tala-data-table__notes">
                                    {{ $item->waiver_reason ?? $item->undertaking_terms ?? 'No additional instruction.' }}
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ match ($item->evidence_method) {
                                        \App\Models\ChecklistItem::EvidenceMethodDigitalUpload => $latestEvidence ? 'File submitted' : 'No file submitted',
                                        \App\Models\ChecklistItem::EvidenceMethodPhysicalCopy => 'No online file required',
                                        \App\Models\ChecklistItem::EvidenceMethodMetadataOnly => 'No file required',
                                        default => 'Not applicable',
                                    } }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @if ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
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
        @endif
    @endif
</x-filament-panels::page>
