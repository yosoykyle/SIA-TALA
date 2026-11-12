@props(['heading', 'date' => null, 'icon' => 'heroicon-o-document-text', 'headingTag' => 'h4'])
<li {{ $attributes->class(['tala-record-timeline-item']) }}>
    <span class="tala-record-timeline-marker"><x-filament::icon :icon="$icon" aria-hidden="true" /></span>
    <div><header><{{ $headingTag }}>{{ $heading }}</{{ $headingTag }}>@if ($date)<time datetime="{{ $date->toIso8601String() }}">{{ $date->copy()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</time>@endif</header>{{ $slot }}</div>
</li>
