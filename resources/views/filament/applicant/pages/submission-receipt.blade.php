<x-filament-panels::page>
    @php($receipt = $this->receipt())
    @php($snapshot = $receipt->snapshot)
    @php($application = $receipt->application)
    <x-filament::section>
        <x-slot name="heading">{{ $receipt->id === $application->current_submission_version_id ? 'Application received' : 'Earlier submitted version' }}</x-slot>
        <x-slot name="description">Version {{ $receipt->version }} · received {{ $receipt->submitted_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} (Asia/Manila)</x-slot>
        <x-application-reference :value="$snapshot['application_reference'] ?? $application->application_reference" />
        <p class="mt-4">{{ collect([$snapshot['first_name'] ?? null, $snapshot['middle_name'] ?? null, $snapshot['last_name'] ?? null, $snapshot['extension_name'] ?? null])->filter()->implode(' ') ?: 'Applicant name was not retained in this version.' }}</p>
        <p>{{ $snapshot['program']['name'] ?? 'Program name was not retained in this version.' }} · {{ \App\Models\AdmissionCycle::studentTypeLabel($snapshot['application_path'] ?? null) }}</p>
        <p>{{ $snapshot['term']['label'] ?? 'Term label was not retained in this version.' }}</p>
        @php($receivedCopies = collect($snapshot['requirements'] ?? [])->filter(fn (array $requirement): bool => filled($requirement['evidence'] ?? null)))
        @if ($receivedCopies->isNotEmpty())
            <p class="mt-4 font-medium">Copies received with this version</p>
            <ul class="mt-2 list-disc ps-5">
                @foreach ($receivedCopies as $requirement)
                    <li>{{ $requirement['label'] ?? 'Requirement' }}</li>
                @endforeach
            </ul>
        @endif
        <p class="mt-4">This receipt confirms the submitted application. Admission and enrollment clearance are recorded separately by the Registrar.</p>
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">What happens next</x-slot>
        <p>Check your current application status for the Registrar's next instruction.</p>
        @if ($receipt->id !== $application->current_submission_version_id)
            <p class="mt-2">These are earlier submitted facts. Follow the current version for any action you need to take.</p>
        @endif
        <details class="mt-4">
            <summary class="cursor-pointer">Instructions issued with version {{ $receipt->version }}</summary>
        @foreach ($snapshot['requirements'] ?? [] as $requirement)
            @if (filled($requirement['applicant_instructions'] ?? null) && ($requirement['due_stage'] ?? null) !== \App\Models\AdmissionRequirement::DuePreliminaryReview)
                <p class="mt-4"><strong>{{ $requirement['label'] ?? 'Requirement' }}:</strong> {{ $requirement['applicant_instructions'] }}</p>
            @endif
        @endforeach
        </details>
        <div class="tala-action-block">
            <x-filament::button tag="a" :href="\App\Filament\Applicant\Pages\Requirements::getUrl(['application' => $application->id])" icon="heroicon-o-arrow-right">View application status</x-filament::button>
            @if ($application->allowsSubmittedEdit())
                <x-filament::button tag="a" color="gray" outlined :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application->id])" icon="heroicon-o-pencil-square">Update application</x-filament::button>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
