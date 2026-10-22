<div class="accounting-proof-context">
    @if ($context !== [])
        <strong>{{ $context['learner'] }}</strong>
        <p>{{ $context['term'] }}</p>
        <dl class="registrar-source-charges">
            <div><dt>Current subject</dt><dd>{{ $context['current_subject'] }}@if ($context['current_class']) · {{ $context['current_class'] }}@endif</dd></div>
            <div><dt>Proposed class</dt><dd>{{ $context['proposed_subject'] }} · {{ $context['proposed_class'] }}</dd></div>
        </dl>
    @endif
</div>
