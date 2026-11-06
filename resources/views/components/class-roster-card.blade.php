@props(['roster', 'href', 'showTerm' => false])
@php
    $specification = $roster->termOffering?->curriculumEntry?->courseSpecification;
    $title = $specification?->title ?? 'Subject title unavailable';
    $class = collect([$specification?->course?->code, $roster->section?->code])->filter()->implode(' · ');
    $submitted = $roster->state === \App\Models\GradeRoster::StateSubmitted;
    [$status, $color, $icon] = match ($roster->state) {
        \App\Models\GradeRoster::StateDraft => ['Draft', 'gray', 'heroicon-m-document-text'],
        \App\Models\GradeRoster::StateSubmitted => ['Awaiting review', 'info', 'heroicon-m-clock'],
        \App\Models\GradeRoster::StateReturned => ['Returned to Faculty', 'warning', 'heroicon-m-arrow-uturn-left'],
        \App\Models\GradeRoster::StateReleased => ['Released', 'success', 'heroicon-m-check-circle'],
        default => ['Awaiting late submission', 'warning', 'heroicon-m-exclamation-triangle'],
    };
@endphp
<article {{ $attributes->class(['tala-class-card', 'tala-task-surface']) }}>
    <header class="tala-class-card-heading">
        <div>
            <h2>{{ $title }}</h2>
            <p>{{ $class ?: 'Class not recorded' }}</p>
        </div>
        <x-filament::badge :color="$color" :icon="$icon">{{ $status }}</x-filament::badge>
    </header>
    <p class="tala-class-card-faculty"><x-filament::icon icon="heroicon-o-user" /><span>{{ $roster->faculty?->getFilamentName() ?? 'Faculty not recorded' }}</span></p>
    @if ($showTerm)
        <p class="tala-class-card-term">{{ collect([$roster->termOffering?->term?->label, $roster->termOffering?->term?->academicYear?->label])->filter()->implode(' · ') }}</p>
    @endif
    <div class="tala-class-card-results">
        <p><strong>{{ number_format($roster->recorded_results_count) }} / {{ number_format($roster->current_members_count) }}</strong><span>results entered</span></p>
        @if ($roster->incomplete_results_count > 0)<p>{{ number_format($roster->incomplete_results_count) }} INC</p>@endif
    </div>
    <x-filament::button tag="a" :href="$href" :color="$submitted ? 'primary' : 'gray'" icon="heroicon-o-eye" :aria-label="($submitted ? 'Review roster for ' : 'View roster for ').$title.', '.$class">
        {{ $submitted ? 'Review roster' : 'View roster' }}
    </x-filament::button>
</article>
