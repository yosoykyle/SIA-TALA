@props(['context', 'showDeadline' => true, 'compact' => false])
<div class="tala-inc-follow-up">
    @if (! $compact)
    <div class="tala-inc-facts">
        <section class="tala-inc-completion-state"><h3>{{ $context['state'] === 'Completion Overdue' ? 'Completion deadline passed' : $context['completion_status'] }}</h3><p>{{ $context['owner'] === 'Faculty' ? ($context['return_reason'] ? 'Faculty must resubmit the completion for Registrar review.' : 'Faculty has not submitted a completion.') : $context['guidance'] }}</p></section>
        @if ($showDeadline)<x-inc-completion-details :context="$context" />@endif
    </div>
    @else
        <p class="tala-profile-muted">{{ $context['state'] === 'Completion Overdue' ? 'Completion deadline passed' : $context['completion_status'] }}</p>
    @endif
    @if ($context['return_reason'])
        <div class="tala-review-feedback"><x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-5" /><div><h3>Reason for return</h3><p>{{ $context['return_reason'] }}</p></div></div>
    @endif
    @if ($context['owner'] === 'Faculty')
        <x-workflow-handoff current-owner="Designated Faculty" current-area="INC completion" current-task="Submit completion of the remaining course work before the deadline." next-owner="Registrar" next-area="INC review and release" next-task="Review the submitted completion before release." />
    @elseif ($context['can_release'])
        <x-workflow-handoff current-owner="Registrar" current-area="INC review and release" current-task="Review and release the Faculty completion, or return it with a reason." next-owner="Student" next-area="Academics · Released grades" next-task="See the result after Registrar release." />
    @elseif ($context['state'] === 'Completion Open')
        <x-workflow-handoff current-owner="Registrar" current-area="INC source review" current-task="Return the outdated completion with a reason." next-owner="Designated Faculty" next-area="INC completion" next-task="Submit a replacement against the current result and open deadline." />
    @else
        <x-workflow-handoff current-owner="Registrar" current-area="INC deadline review" current-task="Ordinary completion is closed. Review an authorized deadline extension if one is provided; the INC remains without credit until a valid completion is released." />
    @endif
</div>
