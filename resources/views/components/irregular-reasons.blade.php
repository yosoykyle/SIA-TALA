@props(['review', 'audience' => 'staff'])
{{-- #99 Scope 11: named reasons behind the Irregular (Individually Advised) label. --}}
<div {{ $attributes->class(['tala-irregular-reasons']) }} data-irregular-status="{{ $review['is_irregular'] ? 'irregular' : 'regular' }}">
    <p class="tala-irregular-reasons__status"><strong>Status:</strong> {{ $review['status_label'] }}</p>
    @if ($review['reasons'] !== [])
        <ol class="tala-irregular-reasons__list">
            @foreach ($review['reasons'] as $index => $reason)
                <li class="tala-irregular-reasons__item tala-irregular-reasons__item--{{ $reason['code'] }}">
                    <p class="tala-irregular-reasons__label">{{ $index === 0 ? 'Primary reason' : 'Secondary reason' }}: <strong>{{ $reason['label'] }}</strong></p>
                    <p class="tala-irregular-reasons__details">{{ $reason['details'] }}</p>
                </li>
            @endforeach
        </ol>
    @elseif ($review['kept_regular'])
        <p class="tala-irregular-reasons__details">{{ $review['decision_reason'] }}</p>
    @else
        <p class="tala-irregular-reasons__details">{{ $audience === 'student' ? 'You are enrolled on your regular curriculum path.' : 'No released result, return or program shift needs an irregular review.' }}</p>
    @endif
</div>
