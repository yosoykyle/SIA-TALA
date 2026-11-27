{{-- Publication checks: calm, scannable checklist with problem, owner, next step, and direct fix action. --}}
<ol class="tala-setup-findings" aria-label="Setup findings and next steps" role="list">
    @foreach ($findings as $finding)
        <li class="tala-setup-finding">
            <div class="tala-setup-finding__body">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="tala-setup-finding__icon" aria-hidden="true" />
                <div class="tala-setup-finding__copy">
                    <div class="tala-setup-finding__head">
                        <p class="tala-setup-finding__reason"><span class="sr-only">Problem: </span>{{ $finding['reason'] }}</p>
                        <span class="tala-setup-finding__owner">Owner: {{ $finding['owner'] }}</span>
                    </div>
                    <p class="tala-setup-finding__action">
                        <span class="tala-setup-finding__label">To fix</span>
                        {{ $finding['next_action'] }}
                    </p>
                </div>
            </div>
            @if ($finding['fix'] ?? null)
                <div class="tala-setup-finding__aside">
                    <x-filament::button tag="a" size="sm" color="primary" :href="$finding['fix']['url']" icon="heroicon-m-arrow-right" icon-position="after" class="tala-setup-finding__fix">
                        {{ $finding['fix']['label'] }}
                    </x-filament::button>
                </div>
            @endif
        </li>
    @endforeach
</ol>
