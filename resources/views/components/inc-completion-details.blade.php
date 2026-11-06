@props(['context', 'deadlineAction' => null])
<section class="tala-inc-deadline">
    <div><h3><x-filament::icon icon="heroicon-o-calendar-days" class="size-4" /> Completion deadline</h3>
    <p><strong>{{ $context['deadline'] }}</strong> · Asia/Manila</p>@if ($deadlineAction)<div class="tala-record-result-actions">{{ $deadlineAction }}</div>@endif</div>
    @if (filled($context['note']))
        <div><h3><x-filament::icon icon="heroicon-o-list-bullet" class="size-4" /> Remaining work</h3><p>{{ $context['note'] }}</p></div>
    @endif
</section>
