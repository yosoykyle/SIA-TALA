@props(['average', 'compact' => false])
@if ($compact)
    <dl class="tala-profile-average-compact">
        <dt>{{ $average['label'] }}</dt>
        <dd><strong>{{ $average['value'] ?? ($average['state'] === \App\Actions\Academics\AcademicAverageReadiness::NotApplicable ? 'Not applicable' : 'Pending') }}</strong>@if ($average['value'] === null)<span class="tala-profile-muted">{{ match ($average['state']) { \App\Actions\Academics\AcademicAverageReadiness::IncompleteResultPending => 'Unresolved INC', \App\Actions\Academics\AcademicAverageReadiness::NotApplicable => 'No eligible graded subjects', default => 'Results incomplete', } }}</span>@endif</dd>
    </dl>
@else

<dl {{ $attributes->class(['space-y-2']) }}>
    <dt class="text-sm text-gray-600 dark:text-gray-300">{{ $average['label'] }}</dt>
    @if ($average['value'] !== null)
        <dd class="text-xl font-semibold tabular-nums">{{ $average['value'] }}</dd>
        @if ($average['through'])<dd class="text-sm">Through {{ $average['through'] }}</dd>@endif
    @else
        <dd><x-filament::badge color="gray">{{ $average['state'] === \App\Actions\Academics\AcademicAverageReadiness::NotApplicable ? 'Not applicable' : 'Pending' }}</x-filament::badge></dd>
    @endif
    @if ($average['reason'])<dd class="text-sm text-gray-600 dark:text-gray-300">{{ $average['reason'] }}</dd>@endif
</dl>

@endif
