@if ($state['valid'])
    @php
        $preview = $state['preview'];
        $pending = $preview['pending_application'] ?? false;
        $hasFinanceReview = data_get($preview, 'finance_effect.mode') === 'accounting_review_required';
        $applicationDate = filled($preview['application_available_on'] ?? null) ? \Carbon\Carbon::parse($preview['application_available_on'])->format('M j, Y') : null;
    @endphp
    <section class="tala-lifecycle-handoff-region" aria-labelledby="lifecycle-handoff-heading">
        <header>
            <h2 class="fi-section-header-heading" id="lifecycle-handoff-heading">What happens next?</h2>
        </header>
        <x-workflow-handoff
            current-owner="Registrar"
            :current-area="$pending ? 'Record approval' : 'Record change'"
            :current-task="$pending ? 'Save approval.' : 'Save change.'"
            :next-owner="$pending ? 'Registrar' : ($hasFinanceReview ? 'Accounting' : null)"
            :next-area="$pending ? 'Apply approval' : 'Financial review'"
            :next-task="$pending ? 'Apply on or after '.$applicationDate.'.' : ($hasFinanceReview ? 'Review financial effects separately.' : null)"
            class="tala-workflow-handoff--band tala-workflow-handoff--compact"
        />
    </section>
@endif
