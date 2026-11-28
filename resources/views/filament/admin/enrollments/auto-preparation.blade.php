@php
    $hasReservedSubjects = $case->current_proposal_version_id !== null;
@endphp
<section class="registrar-requirements registrar-evaluation-task registrar-auto-preparation tala-task-surface" aria-labelledby="auto-preparation-heading">
    <div class="registrar-requirements-heading">
        <x-filament::icon :icon="$regularBlocker['resolved'] ? 'heroicon-o-arrow-path' : 'heroicon-o-exclamation-triangle'" aria-hidden="true" />
        <div>
            <h2 id="auto-preparation-heading">{{ $regularBlocker['resolved'] ? 'Ready to prepare automatically' : ($hasReservedSubjects ? 'Subjects were not sent automatically' : 'Subjects were not prepared automatically') }}</h2>
            <p>{{ $regularBlocker['resolved']
                ? 'The earlier blocker looks cleared. Retry to reserve the block classes, create the assessment and send the subjects.'
                : 'This enrollment follows the standard curriculum, so TALA reserves the block classes, creates the assessment and sends the subjects automatically. Fix what stopped it, then retry.' }}</p>
        </div>
    </div>
    <div @class(['registrar-auto-preparation-reason', 'tala-warning-banner' => ! $regularBlocker['resolved']])>
        <h3>{{ $regularBlocker['resolved'] ? 'Earlier blocker' : 'What stopped it' }}</h3>
        <p>{{ $regularBlocker['reason'] }}</p>
    </div>
    <div class="registrar-case-task-actions">
        {{ $this->getAction('retryAutomaticPreparation', isMounting: false) }}
        @if ($hasReservedSubjects)
            {{ $this->getAction('prepareProposal', isMounting: false) }}
        @endif
    </div>
    <p class="registrar-case-meta">TALA also retries on its own when this term’s classes or Fee Plan are published.</p>
</section>
