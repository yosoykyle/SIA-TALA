<div class="tala-context-inset">
    <strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }} · {{ \App\Actions\Finance\AccountingDisplay::learnerIdentifier($case) }}</strong>
    <p>{{ \App\Models\StudentLifecycleChange::typeOptions()[$source?->type] ?? 'Change source unavailable' }} · {{ $case->term?->label ?? 'Term unavailable' }}</p>
    @if ($source)<p>Effective {{ \App\Actions\Finance\AccountingDisplay::date($source->effective_on) }} · {{ $source->statusLabel() }}</p>@endif
</div>
