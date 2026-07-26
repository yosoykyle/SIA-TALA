<x-filament-panels::page>
    @php
        $intake = $this->getIntake();
        $draftUploadCount = count($intake?->draft_document_references ?? []);
    @endphp

    @if (! $intake)
        {{-- Empty State --}}
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
                    <p class="max-w-md text-zinc-500 dark:text-zinc-400 text-sm leading-relaxed">
                        You do not have an application in progress. Start a new application only when Admissions is open for a term. Earlier applications remain available in Application History below.
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
            <div class="tala-dashboard-grid__primary">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="tala-section-heading">
                            <span>Application Status</span>

                            @php
                                $statusColor = match ($intake->status) {
                                    \App\Models\ApplicantIntake::StatusDraft => 'gray',
                                    \App\Models\ApplicantIntake::StatusPending => 'warning',
                                    \App\Models\ApplicantIntake::StatusActionRequired => 'danger',
                                    \App\Models\ApplicantIntake::StatusForEvaluation => 'info',
                                    \App\Models\ApplicantIntake::StatusApproved => 'success',
                                    \App\Models\ApplicantIntake::StatusWithdrawn => 'gray',
                                    default => 'gray',
                                };
                                $statusLabel = match ($intake->status) {
                                    \App\Models\ApplicantIntake::StatusDraft => 'Draft',
                                    \App\Models\ApplicantIntake::StatusPending => 'Pending Review',
                                    \App\Models\ApplicantIntake::StatusActionRequired => 'Action Required',
                                    \App\Models\ApplicantIntake::StatusForEvaluation => 'Awaiting Evaluation',
                                    \App\Models\ApplicantIntake::StatusApproved => 'Approved for Handover',
                                    \App\Models\ApplicantIntake::StatusWithdrawn => 'Withdrawn',
                                    default => ucfirst($intake->status),
                                };
                            @endphp

                            <x-filament::badge :color="$statusColor" size="lg">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        </div>
                    </x-slot>

                    <dl class="tala-status-grid">
                        <div class="tala-status-grid__item">
                            <dt>Academic Term</dt>
                            <dd>
                                {{ $intake->term?->label ?? 'Not Assigned' }}
                            </dd>
                        </div>
                        <div class="tala-status-grid__item">
                            <dt>Preferred Program</dt>
                            <dd>
                                {{ $intake->program?->name ?? 'Not Assigned' }}
                            </dd>
                        </div>
                        <div class="tala-status-grid__item">
                            <dt>Admission Category</dt>
                            <dd>
                                {{ str_replace('_', ' ', ucfirst(strtolower($intake->admission_category))) }}
                            </dd>
                        </div>
                        <div class="tala-status-grid__item">
                            <dt>Submission Date</dt>
                            <dd>
                                {{ \App\Support\DisplayDateTime::format($intake->submitted_at, 'F j, Y, g:i a', 'Not Submitted') }}
                            </dd>
                        </div>
                    </dl>
                </x-filament::section>
            </div>

            <div class="tala-dashboard-grid__aside">
                <x-filament::section class="h-full">
                    <x-slot name="heading">
                        Next Step
                    </x-slot>

                    <div class="tala-guidance">
                        @if ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
                            <x-filament::callout color="info" icon="heroicon-m-pencil-square">
                                <x-slot name="heading">Draft saved</x-slot>
                                <x-slot name="description">
                                    {{ $draftUploadCount }} {{ $draftUploadCount === 1 ? 'document' : 'documents' }} attached to this draft. Continue the application when you are ready to complete the remaining information and submit it for Registrar review.
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
                                    Your application was submitted successfully. Follow the checklist below and provide any required physical documents to the Registrar's Office.
                                </x-slot>
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
                            <x-filament::callout color="danger" icon="heroicon-m-exclamation-triangle">
                                <x-slot name="heading">A correction is required</x-slot>
                                <x-slot name="description">
                                    Review the Registrar's feedback below, then replace each rejected digital document on the Requirements page.
                                </x-slot>
                            </x-filament::callout>
                            <div class="tala-action-block">
                                <x-filament::button
                                    :href="\App\Filament\Applicant\Pages\Requirements::getUrl()"
                                    tag="a"
                                    icon="heroicon-m-arrow-up-tray"
                                >
                                    Correct Rejected Evidence
                                </x-filament::button>
                            </div>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusForEvaluation)
                            <x-filament::callout color="info" icon="heroicon-m-magnifying-glass">
                                <x-slot name="heading">Credential evaluation in progress</x-slot>
                                <x-slot name="description">
                                    The required evidence has been received. The Registrar is evaluating your admission record before student handover.
                                </x-slot>
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusApproved)
                            <x-filament::callout color="success" icon="heroicon-m-check-circle">
                                <x-slot name="heading">Application approved</x-slot>
                                <x-slot name="description">
                                    Your admission application is approved. Student Hub access becomes available after the Registrar completes student handover.
                                </x-slot>
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusWithdrawn)
                            <x-filament::callout color="gray" icon="heroicon-m-archive-box-x-mark">
                                <x-slot name="heading">Application withdrawn</x-slot>
                                <x-slot name="description">
                                    This application remains in the audit record and cannot continue online.
                                    @if ($intake->archived_at)
                                        It was withdrawn on {{ \App\Support\DisplayDateTime::format($intake->archived_at, 'F j, Y \a\t g:i A') }}.
                                    @endif
                                    Contact the Registrar if you need to apply again.
                                </x-slot>
                            </x-filament::callout>
                            <dl class="tala-summary-list">
                                <div>
                                    <dt>Withdrawal reason</dt>
                                    <dd>{{ $intake->withdrawalActivity?->properties?->get('reason') ?? 'No reason was recorded.' }}</dd>
                                </div>
                            </dl>
                        @endif
                    </div>
                </x-filament::section>
            </div>
        </div>

        @if ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
            <x-filament::section>
                <x-slot name="heading">Draft progress</x-slot>

                <div class="tala-draft-progress">
                    <p>
                        <strong>{{ $draftUploadCount }} {{ $draftUploadCount === 1 ? 'document' : 'documents' }} attached to this draft.</strong>
                        You can return to the application and replace or add files before submission.
                    </p>
                    <p>The Registrar checklist and review history are created after you submit the application.</p>
                </div>
            </x-filament::section>
        @else
        {{-- Checklist Table --}}
        <x-filament::section>
            <x-slot name="heading">
                Required Documents
            </x-slot>

            @if ($intake->checklistItems->isEmpty())
                <x-filament::empty-state icon="heroicon-o-clipboard-document-check">
                    <x-slot name="heading">Checklist unavailable</x-slot>
                    <x-slot name="description">
                        This submitted application does not yet have requirement records. Contact the Registrar for assistance.
                    </x-slot>
                </x-filament::empty-state>
            @else
            <div class="tala-table-scroll">
                <table class="tala-data-table">
                    <thead>
                        <tr>
                            <th>Document / Requirement</th>
                            <th>Why it matters</th>
                            <th>How to provide it</th>
                            <th>Status</th>
                            <th>Registrar feedback</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($intake->checklistItems as $item)
                            <tr>
                                <td class="tala-data-table__primary">
                                    {{ str_replace('_', ' ', ucfirst($item->requirement_type)) }}
                                </td>
                                <td>
                                    @php
                                        $blockColor = match ($item->blocking_level) {
                                            \App\Models\ChecklistItem::BlockingHandover => 'danger',
                                            \App\Models\ChecklistItem::BlockingEnrollment => 'warning',
                                            default => 'gray',
                                        };
                                        $blockLabel = match ($item->blocking_level) {
                                            \App\Models\ChecklistItem::BlockingHandover => 'Blocks Handover',
                                            \App\Models\ChecklistItem::BlockingEnrollment => 'Blocks Enrollment',
                                            default => str_replace('_', ' ', ucfirst(strtolower($item->blocking_level))),
                                        };
                                    @endphp
                                    <x-filament::badge :color="$blockColor" size="sm">
                                        {{ $blockLabel }}
                                    </x-filament::badge>
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ str_replace('_', ' ', ucfirst(strtolower($item->evidence_method))) }}
                                </td>
                                <td>
                                    @php
                                        $itemColor = match ($item->status) {
                                            \App\Models\ChecklistItem::StatusAccepted, \App\Models\ChecklistItem::StatusWaived, \App\Models\ChecklistItem::StatusUndertakingApproved => 'success',
                                            \App\Models\ChecklistItem::StatusPending => 'warning',
                                            \App\Models\ChecklistItem::StatusRejected => 'danger',
                                            \App\Models\ChecklistItem::StatusReceivedDigital, \App\Models\ChecklistItem::StatusReceivedPhysical => 'info',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-filament::badge :color="$itemColor" size="sm">
                                        {{ str_replace('_', ' ', ucfirst(strtolower($item->status))) }}
                                    </x-filament::badge>
                                </td>
                                <td class="tala-data-table__notes">
                                    {{ $item->waiver_reason ?? $item->undertaking_terms ?? 'No feedback provided.' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-filament::section>

        {{-- Upload History / Document Submissions --}}
        <x-filament::section>
            <x-slot name="heading">
                Submitted Digital Documents
            </x-slot>

            @php
                $submittedUploads = $intake->checklistItems->flatMap->documentEvidence;
            @endphp

            @if ($submittedUploads->isEmpty())
                <x-filament::empty-state icon="heroicon-o-document">
                    <x-slot name="heading">No submitted digital documents</x-slot>
                    <x-slot name="description">
                        No reviewable digital evidence is attached to this submitted application. Contact the Registrar for assistance.
                    </x-slot>
                </x-filament::empty-state>
            @else
            <div class="tala-table-scroll">
                <table class="tala-data-table">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th>File Name</th>
                            <th>Submitted</th>
                            <th>Review Status</th>
                            <th>Reviewed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($submittedUploads as $upload)
                            <tr>
                                <td class="tala-data-table__primary">
                                    {{ str_replace('_', ' ', ucfirst(strtolower($upload->checklistItem->requirement_type))) }}
                                </td>
                                <td class="tala-data-table__notes">
                                    {{ basename($upload->path) }}
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ \App\Support\DisplayDateTime::format($upload->uploaded_at, 'M d, Y, h:i A', 'N/A') }}
                                </td>
                                <td>
                                    @php
                                        $reviewColor = $upload->status === 'ACCEPTED' ? 'success' : 'warning';
                                        $reviewLabel = str_replace('_', ' ', ucfirst(strtolower($upload->status)));
                                    @endphp
                                    <x-filament::badge :color="$reviewColor" size="sm">
                                        {{ $reviewLabel }}
                                    </x-filament::badge>
                                </td>
                                <td class="tala-data-table__muted">
                                    {{ $upload->reviewer?->name ?? 'Awaiting Review' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-filament::section>
        @endif
    @endif

    <x-filament::section>
        <x-slot name="heading">Application History</x-slot>
        <x-slot name="description">
            Review your earlier and current admission records. Withdrawal reasons and other sensitive details appear only after you select View.
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
