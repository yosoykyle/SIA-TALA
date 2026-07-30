<x-filament-panels::page>
    @if (! $this->snapshot || ! $this->projection)
        <x-filament::section icon="heroicon-o-clipboard-document-check">
            <x-slot name="heading">No completion eligibility review has been shared</x-slot>
            <x-slot name="description">
                The Registrar will share a review here when there is a result for you to see. No action is needed unless the Registrar has directed you otherwise.
            </x-slot>
        </x-filament::section>
    @else
        @php
            $remainingRequirements = $this->projection['remaining_requirements'] ?? [];
            $failedRequirements = $this->projection['failed_requirements'] ?? [];
            $inProgressRequirements = $this->projection['in_progress_requirements'] ?? [];
            $pendingGradeBlockers = $this->projection['pending_grade_blockers'] ?? [];
            $incBlockers = $this->projection['inc_blockers'] ?? [];
            $holdItems = $this->projection['hold_or_clearance_items'] ?? [];
            $legacyHoldLabels = $this->projection['hold_or_clearance_labels'] ?? [];
            $offices = $this->projection['offices_to_contact'] ?? [$this->projection['office_to_contact'] ?? 'Registrar Office'];
            $resultStatus = $this->projection['result_status'] ?? $this->snapshot->result_status;
            $statusLabel = $this->projection['status_label'] ?? match ($resultStatus) {
                \App\Actions\Graduation\GraduationEligibilitySnapshotService::ResultComplete => 'Requirements complete for Registrar review',
                \App\Actions\Graduation\GraduationEligibilitySnapshotService::ResultReadyForRegistrarReview => 'Current requirements in progress',
                default => str($resultStatus)->after('Blocked: ')->prepend('Review blocked: ')->toString(),
            };
            $requiredAction = $resultStatus === \App\Actions\Graduation\GraduationEligibilitySnapshotService::ResultComplete
                ? 'No action is required for this review. Wait for the Registrar to complete the official graduation process.'
                : ($this->projection['required_action'] ?? 'Review the listed requirements and contact the Registrar Office if you need clarification.');
        @endphp

        <div class="space-y-6">
            <x-filament::section icon="heroicon-o-clipboard-document-check">
                <x-slot name="heading">Completion eligibility review</x-slot>
                <x-slot name="description">
                    Evaluated {{ \App\Support\DisplayDateTime::format($this->snapshot->generated_at, 'M d, Y g:i A') }}
                </x-slot>

                <x-filament::badge color="warning" size="lg">
                    {{ $statusLabel }}
                </x-filament::badge>

                <p class="tala-requirement-summary">
                    This eligibility review does not confer a degree or replace the Registrar’s official graduation process.
                </p>

                <dl class="tala-status-grid">
                    <div class="tala-status-grid__item">
                        <dt>Remaining curriculum units</dt>
                        <dd>
                        {{ number_format((float) ($this->projection['remaining_units'] ?? 0), 1) }}
                        </dd>
                    </div>
                </dl>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-list-bullet">
                <x-slot name="heading">Requirements to complete</x-slot>

                <ul class="tala-draft-progress">
                    @foreach ($remainingRequirements as $requirement)
                        <li>{{ $requirement }}</li>
                    @endforeach
                    @foreach ($failedRequirements as $requirement)
                        <li><strong>Failed:</strong> {{ $requirement }}</li>
                    @endforeach
                    @if (blank($remainingRequirements) && blank($failedRequirements))
                        <li>No remaining or failed requirements are listed.</li>
                    @endif
                </ul>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-clock">
                <x-slot name="heading">Current and pending work</x-slot>

                <ul class="tala-draft-progress">
                    @foreach ($inProgressRequirements as $requirement)
                        <li><strong>In progress:</strong> {{ $requirement }}</li>
                    @endforeach
                    @foreach ($pendingGradeBlockers as $requirement)
                        <li><strong>Pending grade:</strong> {{ $requirement }}</li>
                    @endforeach
                    @foreach ($incBlockers as $requirement)
                        <li><strong>Incomplete:</strong> {{ $requirement }}</li>
                    @endforeach
                    @if (blank($inProgressRequirements) && blank($pendingGradeBlockers) && blank($incBlockers))
                        <li>No current, pending-grade, or incomplete items are listed.</li>
                    @endif
                </ul>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-exclamation-triangle">
                <x-slot name="heading">Holds and clearance items</x-slot>

                <ul class="tala-draft-progress">
                    @forelse ($holdItems as $item)
                        <li>
                            <strong>{{ $item['label'] ?? 'Hold or clearance item' }}</strong>
                            @if (filled($item['message'] ?? $item['student_message'] ?? null))
                                <span>{{ $item['message'] ?? $item['student_message'] }}</span>
                            @endif
                            <span>Office: {{ $item['office'] ?? $item['office_to_contact'] ?? 'Registrar Office' }}</span>
                        </li>
                    @empty
                        @forelse ($legacyHoldLabels as $label)
                            <li>{{ $label }}</li>
                        @empty
                            <li>No hold or clearance items are listed.</li>
                        @endforelse
                    @endforelse
                </ul>
            </x-filament::section>

            <x-filament::section icon="heroicon-o-arrow-right-circle">
                <x-slot name="heading">What to do next</x-slot>
                <x-slot name="description">{{ $requiredAction }}</x-slot>

                <dl class="tala-status-grid">
                    <div class="tala-status-grid__item">
                        <dt>Offices to contact</dt>
                        <dd>
                            <ul class="tala-guidance">
                    @foreach ($offices as $office)
                        <li>{{ $office }}</li>
                    @endforeach
                            </ul>
                        </dd>
                    </div>
                </dl>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
