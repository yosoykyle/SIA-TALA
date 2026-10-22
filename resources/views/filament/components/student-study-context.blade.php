<section class="student-study-summary tala-task-surface" aria-label="Current studies">
    <x-filament::icon icon="heroicon-o-academic-cap" class="student-context-icon" />
    <div class="student-context-body">
        <h2>{{ $contextProgram }}</h2>
        @if ($contextFacts !== [])
            <dl class="student-context-facts">
                @foreach ($contextFacts as $label => $value)<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
            </dl>
        @endif
        @if (filled($contextNote ?? null))<p class="student-academic-note">{{ $contextNote }}</p>@endif
    </div>
    @if (filled($contextActionUrl ?? null))
        <x-filament::button tag="a" :href="$contextActionUrl" icon="heroicon-o-document-text">View registration</x-filament::button>
    @elseif (filled($contextActionMessage ?? null))
        <p class="student-academic-note">{{ $contextActionMessage }}</p>
    @endif
</section>
