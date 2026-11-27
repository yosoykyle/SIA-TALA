@props(['review', 'audience' => 'staff'])
{{-- #99 Scope 11: named reasons behind the Irregular (Individually Advised) label. --}}
@php
    $isIrregular = (bool) ($review['is_irregular'] ?? false);
    $reasons = $review['reasons'] ?? [];
    $reasonCount = count($reasons);
    $primaryCode = $reasons[0]['code'] ?? null;
    $isDangerTone = $isIrregular && $primaryCode === 'failed';
@endphp
<div {{ $attributes->class([
    'tala-irregular-reasons',
    'tala-irregular-reasons--irregular' => $isIrregular,
    'tala-irregular-reasons--regular' => ! $isIrregular,
]) }} data-irregular-status="{{ $isIrregular ? 'irregular' : 'regular' }}">
    <div class="tala-irregular-reasons__header">
        <p @class([
            'tala-irregular-reasons__status',
            'tala-irregular-reasons__status--irregular' => $isIrregular,
            'tala-irregular-reasons__status--danger' => $isDangerTone,
            'tala-irregular-reasons__status--regular' => ! $isIrregular,
        ])>
            <x-filament::icon
                :icon="$isIrregular ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle'"
                class="tala-irregular-reasons__status-icon"
                aria-hidden="true"
            />
            <span>Status: {{ $review['status_label'] }}</span>
        </p>
        @if ($audience !== 'student' && ($review['has_high_deficiency_count'] ?? false))
            <span class="tala-irregular-reasons__count" title="Advisory only (PRD 05 §246): does not auto-block enrollment or assign probation/repeat-year.">
                {{ $review['unresolved_deficiency_count'] }} unresolved deficiencies · Review advisory
            </span>
        @endif
        @if ($reasonCount > 1)
            <span class="tala-irregular-reasons__count">{{ $reasonCount }} factors</span>
        @endif
    </div>
    @if ($reasons !== [])
        <ol class="tala-irregular-reasons__list">
            @foreach ($reasons as $index => $reason)
                @php
                    $isPrimary = $index === 0;
                    $code = $reason['code'] ?? 'other';
                @endphp
                <li @class([
                    'tala-irregular-reasons__item',
                    'tala-irregular-reasons__item--primary' => $isPrimary,
                    'tala-irregular-reasons__item--secondary' => ! $isPrimary,
                    'tala-irregular-reasons__item--'.$code,
                ])>
                    <div class="tala-irregular-reasons__item-head">
                        <span class="tala-irregular-reasons__tier">{{ $isPrimary ? 'Primary reason' : 'Secondary reason' }}</span>
                        <strong class="tala-irregular-reasons__title">{{ $reason['label'] }}</strong>
                    </div>
                    <p class="tala-irregular-reasons__details">{{ $reason['details'] }}</p>
                </li>
            @endforeach
        </ol>
    @elseif ($review['kept_regular'] ?? false)
        <p class="tala-irregular-reasons__note">{{ $review['decision_reason'] }}</p>
    @else
        <p class="tala-irregular-reasons__note">{{ $audience === 'student' ? 'You are enrolled on your regular curriculum path.' : 'No released result, return or program shift needs an irregular review.' }}</p>
    @endif
</div>
