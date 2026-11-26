{{-- Publication checks: each blocker reads as what is wrong (amber), then how to fix it (green strip), with its owner and a link to the fix. --}}
<ol class="tala-setup-findings" aria-label="Setup findings and next steps">
    @foreach ($findings as $finding)
        <li class="tala-setup-finding">
            <div class="tala-setup-finding__problem">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="tala-setup-finding__icon" />
                <p class="tala-setup-finding__reason"><span class="sr-only">Problem: </span>{{ $finding['reason'] }}</p>
                <span class="tala-setup-finding__owner">Owner: {{ $finding['owner'] }}</span>
            </div>
            <div class="tala-setup-finding__resolution">
                <p class="tala-setup-finding__action">
                    <span class="tala-setup-finding__label">To fix</span>
                    {{ $finding['next_action'] }}
                </p>
                @if ($finding['fix'] ?? null)
                    <x-filament::button tag="a" size="sm" :href="$finding['fix']['url']" icon="heroicon-m-arrow-right" icon-position="after" class="tala-setup-finding__fix">
                        {{ $finding['fix']['label'] }}
                    </x-filament::button>
                @endif
            </div>
        </li>
    @endforeach
</ol>
