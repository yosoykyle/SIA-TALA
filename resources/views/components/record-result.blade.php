@props(['result', 'description' => 'Official result', 'releasedOn' => null, 'completion' => null, 'deadlineAction' => null])
<div @class(['tala-record-result', 'tala-record-result--completion' => $completion !== null])>
    <div><p>Current released result</p><strong>{{ $result ?? '—' }}</strong>@if ($completion)<p class="tala-record-release-date">{{ $description }}@if ($releasedOn) · Released {{ $releasedOn }}@endif</p>@endif
        @if (isset($actions) && $actions->isNotEmpty())<div class="tala-record-result-actions">{{ $actions }}</div>@endif
    </div>
    @if ($completion)
        <x-inc-completion-details :context="$completion" :deadline-action="$deadlineAction" />
    @else
    <div class="tala-record-result-context"><x-filament::icon icon="heroicon-o-check-badge" class="size-5" /><span>{{ $description }}@if ($releasedOn)<small>Released {{ $releasedOn }}</small>@endif</span></div>
    @endif
</div>
