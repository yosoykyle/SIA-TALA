<x-filament::callout color="warning" icon="heroicon-m-exclamation-triangle">
    <x-slot name="heading">{{ $activeCorrection->isOverdue() ? 'Corrections are still open' : 'Corrections requested' }}</x-slot>
    <x-slot name="description">
        <p>Update the marked items, then review and resubmit.</p>
        @if ($activeCorrection->changesStudentType())
            <p class="mt-2"><strong>Requested Student type:</strong> {{ \App\Models\AdmissionCycle::studentTypeLabel($activeCorrection->target_application_path) }}. Your changes are submitted together at the final step.</p>
        @endif
        <p class="mt-2"><strong>Registrar's instructions</strong></p>
        <p class="whitespace-pre-line">{{ $activeCorrection->applicant_instruction }}</p>
        <p class="mt-2">Resubmit by {{ $activeCorrection->due_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} (Asia/Manila).@if ($activeCorrection->isOverdue()) You can still resubmit.@endif</p>
        <details class="mt-3">
            <summary class="cursor-pointer">View requested fields and copies</summary>
            <div class="mt-3 space-y-3">
                @if ($correctionSteps ?? [])
                    <ul class="space-y-2" aria-label="Requested corrections">
                        @foreach ($correctionSteps as $scope)
                            <li><strong>{{ $scope['label'] }}:</strong> {{ implode(', ', $scope['items']) }}</li>
                        @endforeach
                    </ul>
                @else
                    <ul class="space-y-2" aria-label="Requested corrections">
                        @foreach ($activeCorrection->items as $item)
                            <li>{{ $item->scope_type === \App\Models\ApplicationCorrectionItem::ScopeEvidence
                                ? ($item->admissionRequirement?->label ?? 'Requested copy')
                                : (\App\Models\ApplicationCorrectionItem::fieldOptions()[$item->scope_key] ?? str($item->scope_key)->headline()) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </details>
    </x-slot>
</x-filament::callout>
