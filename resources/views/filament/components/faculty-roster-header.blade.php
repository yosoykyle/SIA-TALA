@php
    $classCrumb = collect([
        $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code,
        $roster->section?->code,
    ])->filter()->implode(' · ') ?: $heading;

    $statusColor = match ($roster->state) {
        \App\Models\GradeRoster::StateReturned, \App\Models\GradeRoster::StateLateNotSubmitted => 'warning',
        \App\Models\GradeRoster::StateReleased => 'success',
        \App\Models\GradeRoster::StateSubmitted => 'info',
        default => 'gray',
    };

    $statusLabel = match ($roster->state) {
        \App\Models\GradeRoster::StateReturned => 'Changes requested',
        \App\Models\GradeRoster::StateReleased => $roster->released_at
            ? 'Released · '.$roster->released_at->copy()->timezone('Asia/Manila')->format('M j, Y')
            : 'Released',
        \App\Models\GradeRoster::StateSubmitted => $roster->submitted_at
            ? 'Submitted · '.$roster->submitted_at->copy()->timezone('Asia/Manila')->format('M j, Y')
            : 'Submitted for Registrar review',
        \App\Models\GradeRoster::StateLateNotSubmitted => 'Submission overdue',
        default => 'Draft',
    };

    $isEntryOpen = (bool) ($entryWindow['is_open'] ?? false);
    $closesAt = $entryWindow['closes_at'] ?? null;
    $entryHeadline = $isEntryOpen
        ? (($entryWindow['basis'] ?? '') === 'Authorized late grade entry' ? 'Authorized late grade entry open' : 'Grade entry open')
        : ($closesAt ? 'Grade entry closed' : 'Grade entry not open');
    $entryTimeText = $closesAt
        ? ($isEntryOpen ? 'Closes ' : 'Closed ').$closesAt->copy()->timezone('Asia/Manila')->format('M j, Y, g:i A').' (Asia/Manila)'
        : 'Dates not configured';
    $designatedName = $roster->teachingAssignment?->faculty?->name ?? $roster->faculty?->name ?? 'Not recorded';
@endphp

<header class="faculty-class-header">
    <div class="faculty-class-header-navigation">
        @if ($hasMultipleClasses)
            <x-filament::breadcrumbs :breadcrumbs="[
                $this::getUrl(['classes' => 1]) => 'All classes',
                $classCrumb,
            ]" />
        @else
            <span class="faculty-class-breadcrumb-current">{{ $classCrumb }}</span>
        @endif
        <div class="faculty-class-header-utilities">
            @foreach ($actions as $action)
                @if (in_array($action->getName(), ['printRoster', 'downloadRoster'], true) && $action->isVisible())
                    {{ $action }}
                @endif
            @endforeach
        </div>
    </div>
    <div class="faculty-class-header-identity">
        <h1 class="fi-header-heading">{{ $heading }}</h1>
        <p class="fi-header-subheading">{{ $subheading }}</p>
        <div class="faculty-class-header-badges">
            <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
        </div>
    </div>
    <div class="faculty-grade-entry-bar" role="status">
        <span class="faculty-grade-entry-bar__item">
            <x-filament::icon :icon="$isEntryOpen ? 'heroicon-o-clock' : 'heroicon-o-lock-closed'" class="size-4" aria-hidden="true" />
            <strong>{{ $entryHeadline }}</strong>
            <span>· {{ $entryTimeText }}</span>
        </span>
        @if (! $designated)
            <span class="faculty-grade-entry-bar__item faculty-grade-entry-bar__authority">
                <span>View-only · Designated faculty: {{ $designatedName }}</span>
            </span>
        @endif
    </div>
    @foreach ($actions as $action)
        @if (! in_array($action->getName(), ['selectRoster', 'printRoster', 'downloadRoster'], true) && $action->isVisible())
            <div>{{ $action }}</div>
        @endif
    @endforeach
</header>
