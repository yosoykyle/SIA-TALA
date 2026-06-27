<x-filament-panels::page>
    @php
        $intake = $this->getIntake();
    @endphp

    @if (! $intake)
        {{-- Empty State --}}
        <x-filament::section class="max-w-4xl mx-auto py-8">
            <div class="flex flex-col items-center text-center gap-6 py-6">
                <div class="p-4 rounded-full bg-primary-50 dark:bg-primary-950/20 text-primary-600 dark:text-primary-400">
                    <svg class="size-16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                </div>

                <div class="space-y-2">
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">
                        Start Your Application
                    </h2>
                    <p class="max-w-md text-zinc-500 dark:text-zinc-400 text-sm leading-relaxed">
                        Welcome to your Applicant Workspace. Before we can process your admission, please start and submit your official intake profile.
                    </p>
                </div>

                <div class="pt-2">
                    <x-filament::button
                        :href="\App\Filament\Applicant\Pages\Application::getUrl()"
                        tag="a"
                        icon="heroicon-m-document-text"
                        size="lg"
                    >
                        Start Application
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @else
        {{-- Application Status Card --}}
        <div class="grid gap-6 md:grid-cols-3">
            <div class="md:col-span-2">
                <x-filament::section>
                    <x-slot name="heading">
                        <div class="flex items-center justify-between flex-wrap gap-4">
                            <span class="text-lg font-bold tracking-tight">Application Status</span>
                            
                            @php
                                $statusColor = match ($intake->status) {
                                    \App\Models\ApplicantIntake::StatusDraft => 'gray',
                                    \App\Models\ApplicantIntake::StatusPending => 'warning',
                                    \App\Models\ApplicantIntake::StatusActionRequired => 'danger',
                                    \App\Models\ApplicantIntake::StatusForEvaluation => 'info',
                                    \App\Models\ApplicantIntake::StatusApproved => 'success',
                                    default => 'gray',
                                };
                                $statusLabel = match ($intake->status) {
                                    \App\Models\ApplicantIntake::StatusDraft => 'Draft',
                                    \App\Models\ApplicantIntake::StatusPending => 'Pending Review',
                                    \App\Models\ApplicantIntake::StatusActionRequired => 'Action Required',
                                    \App\Models\ApplicantIntake::StatusForEvaluation => 'Awaiting Evaluation',
                                    \App\Models\ApplicantIntake::StatusApproved => 'Approved for Handover',
                                    default => ucfirst($intake->status),
                                };
                            @endphp

                            <x-filament::badge :color="$statusColor" size="lg" class="px-3 py-1 font-bold">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        </div>
                    </x-slot>

                    <div class="grid gap-4 sm:grid-cols-2 pt-2">
                        <div>
                            <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Academic Term</span>
                            <p class="text-sm font-medium text-zinc-950 dark:text-white mt-1">
                                {{ $intake->term?->term_name ?? 'Not Assigned' }}
                            </p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Preferred Program</span>
                            <p class="text-sm font-medium text-zinc-950 dark:text-white mt-1">
                                {{ $intake->program?->name ?? 'Not Assigned' }}
                            </p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Applicant Type</span>
                            <p class="text-sm font-medium text-zinc-950 dark:text-white mt-1">
                                {{ ucfirst($intake->applicant_type) }}
                            </p>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Submission Date</span>
                            <p class="text-sm font-medium text-zinc-950 dark:text-white mt-1">
                                {{ $intake->submitted_at?->format('F j, Y, g:i a') ?? 'Not Submitted' }}
                            </p>
                        </div>
                    </div>
                </x-filament::section>
            </div>

            {{-- Next Step Guidance Card --}}
            <div>
                <x-filament::section class="h-full">
                    <x-slot name="heading">
                        <span class="text-lg font-bold tracking-tight">Next Step Guidance</span>
                    </x-slot>

                    <div class="pt-2 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                        @if ($intake->status === \App\Models\ApplicantIntake::StatusDraft)
                            <x-filament::callout type="info" icon="heroicon-m-pencil-square">
                                Your application is still a draft. Complete the required information and submit it for Registrar review.
                            </x-filament::callout>
                            <div class="mt-4">
                                <x-filament::button
                                    :href="\App\Filament\Applicant\Pages\Application::getUrl()"
                                    tag="a"
                                    icon="heroicon-m-pencil-square"
                                >
                                    Continue Application
                                </x-filament::button>
                            </div>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusPending)
                            <x-filament::callout type="warning" icon="heroicon-m-clock">
                                Your application has been submitted and is currently being processed. Please submit the physical copies of your documents to the Registrar's Office as soon as possible.
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusActionRequired)
                            <x-filament::callout type="danger" icon="heroicon-m-exclamation-triangle">
                                The Registrar has requested corrections on your submitted documents. Please check the checklist items table below and re-upload the corrected versions of the rejected files.
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusForEvaluation)
                            <x-filament::callout type="info" icon="heroicon-m-magnifying-glass">
                                All required digital document uploads have been received. The Registrar is currently evaluating your credentials for official student handover.
                            </x-filament::callout>
                        @elseif ($intake->status === \App\Models\ApplicantIntake::StatusApproved)
                            <x-filament::callout type="success" icon="heroicon-m-check-circle">
                                Congratulations! Your admission application has been approved. The system will activate your Student Hub access once the student handover processes are complete.
                            </x-filament::callout>
                        @endif
                    </div>
                </x-filament::section>
            </div>
        </div>

        {{-- Checklist Table --}}
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-lg font-bold tracking-tight">Required Documents Checklist</span>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold uppercase tracking-wider">
                            <th class="py-3 px-4">Document / Requirement</th>
                            <th class="py-3 px-4">Blocking Level</th>
                            <th class="py-3 px-4">Evidence Type</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Notes / Feedback</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-sm">
                        @forelse ($intake->checklistItems as $item)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                <td class="py-3 px-4 font-medium text-zinc-900 dark:text-white">
                                    {{ str_replace('_', ' ', ucfirst($item->requirement_type)) }}
                                </td>
                                <td class="py-3 px-4 text-zinc-500">
                                    @php
                                        $blockColor = match ($item->blocking_level) {
                                            'blocks_handover' => 'danger',
                                            'blocks_enrollment' => 'warning',
                                            default => 'gray',
                                        };
                                        $blockLabel = match ($item->blocking_level) {
                                            'blocks_handover' => 'Blocks Handover',
                                            'blocks_enrollment' => 'Blocks Enrollment',
                                            default => ucfirst($item->blocking_level),
                                        };
                                    @endphp
                                    <x-filament::badge :color="$blockColor" size="sm">
                                        {{ $blockLabel }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-3 px-4 text-zinc-500">
                                    {{ str_replace('_', ' ', ucfirst($item->evidence_method)) }}
                                </td>
                                <td class="py-3 px-4">
                                    @php
                                        $itemColor = match ($item->status) {
                                            'accepted', 'verified' => 'success',
                                            'pending' => 'warning',
                                            'rejected' => 'danger',
                                            'received_digital', 'received_physical' => 'info',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-filament::badge :color="$itemColor" size="sm">
                                        {{ str_replace('_', ' ', ucfirst($item->status)) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-3 px-4 text-zinc-500 max-w-xs truncate">
                                    {{ $item->notes ?? 'No feedback provided.' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-zinc-500">
                                    No requirements configured for this application.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Upload History / Document Submissions --}}
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-lg font-bold tracking-tight">Recent Digital Uploads</span>
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 text-xs font-semibold uppercase tracking-wider">
                            <th class="py-3 px-4">Document Type</th>
                            <th class="py-3 px-4">File Name</th>
                            <th class="py-3 px-4">Submitted At</th>
                            <th class="py-3 px-4">Review Status</th>
                            <th class="py-3 px-4">Reviewed By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 text-sm">
                        @forelse ($intake->documentUploads as $upload)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50">
                                <td class="py-3 px-4 font-medium text-zinc-900 dark:text-white">
                                    {{ str_replace('_', ' ', ucfirst($upload->document_type)) }}
                                </td>
                                <td class="py-3 px-4 text-zinc-500 truncate max-w-xs">
                                    {{ $upload->file_name }}
                                </td>
                                <td class="py-3 px-4 text-zinc-500">
                                    {{ $upload->created_at?->format('M d, Y, h:i A') ?? 'N/A' }}
                                </td>
                                <td class="py-3 px-4">
                                    @php
                                        $reviewColor = \App\Models\DocumentUpload::reviewStatusColor($upload->review_status);
                                        $reviewLabel = \App\Models\DocumentUpload::reviewStatusOptions()[$upload->review_status] ?? ucfirst($upload->review_status);
                                    @endphp
                                    <x-filament::badge :color="$reviewColor" size="sm">
                                        {{ $reviewLabel }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-3 px-4 text-zinc-500">
                                    {{ $upload->registrarReviewer?->name ?? 'Awaiting Review' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-zinc-500">
                                    No digital uploads recorded yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
