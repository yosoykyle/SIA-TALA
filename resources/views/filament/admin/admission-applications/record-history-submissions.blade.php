<div class="tala-record-history">
    @if (count($submissions) === 0)
        <p class="tala-history-empty">No submission yet. The saved Draft remains in Applicant details.</p>
    @else
        <ul class="tala-history-rows" aria-label="Submissions, newest first">
            @foreach ($submissions as $submission)
                <li>
                    <div class="tala-history-line">
                        <span class="tala-history-name">Submission #{{ $submission['number'] }}</span>
                        <x-filament::badge :color="$submission['current'] ? 'success' : 'gray'">{{ $submission['current'] ? 'Current' : 'Earlier' }}</x-filament::badge>
                        <span class="tala-history-meta">{{ $submission['by'] }} · {{ $submission['at'] }}</span>
                        <a class="tala-history-link" href="{{ $submission['url'] }}" target="_blank" rel="noopener" aria-label="Open acknowledgment for Submission #{{ $submission['number'] }}">
                            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" />Acknowledgment
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
