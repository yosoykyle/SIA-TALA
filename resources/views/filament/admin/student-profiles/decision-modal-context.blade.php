@props([
    'student',
    'effect' => null,
    'termScope' => null,
    'startsOn' => null,
    'endsOn' => null,
    'termLabels' => [],
])

@php
    $studentName = collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    $initials = mb_strtoupper(mb_substr((string) $student->first_name, 0, 1) . mb_substr((string) $student->last_name, 0, 1));
    $programName = $student->program?->name ?? 'No program assigned';
    $photoUrl = $student->user?->getFilamentAvatarUrl();

    $hasScopeSelection = filled($termScope);
    $isAllTerms = ($termScope === 'all');
    $scopeSummary = match (true) {
        $isAllTerms => 'student-wide across all terms',
        $hasScopeSelection => 'for ' . ($termLabels[$termScope] ?? 'the selected term'),
        default => null,
    };

    $startsOnDate = filled($startsOn) ? \Illuminate\Support\Carbon::parse($startsOn)->format('M j, Y') : null;
    $endsOnDate = filled($endsOn) ? \Illuminate\Support\Carbon::parse($endsOn)->format('M j, Y') : null;

    $dateSummary = match(true) {
        filled($startsOnDate) && filled($endsOnDate) => "from {$startsOnDate} until {$endsOnDate}",
        filled($startsOnDate) => "from {$startsOnDate}",
        default => '',
    };

    $hasSelection = filled($effect) && $hasScopeSelection;

    $headline = match (true) {
        ! $hasSelection => 'Select enrollment effect and scope',
        $effect === \App\Models\AcademicDecision::EffectAdvisingRequired => $isAllTerms
            ? "Advising is required student-wide {$dateSummary}."
            : "Advising is required for this term {$dateSummary}.",
        $effect === \App\Models\AcademicDecision::EffectAllowed => "Enrollment is allowed {$scopeSummary} {$dateSummary}.",
        $effect === \App\Models\AcademicDecision::EffectBlocked => "Enrollment is blocked {$scopeSummary} {$dateSummary}.",
        $effect === \App\Models\AcademicDecision::EffectPendingDecision => "Academic decision is pending {$scopeSummary} {$dateSummary}.",
        default => 'Select enrollment effect and scope',
    };

    $description = match (true) {
        ! $hasSelection => 'Choose an enrollment effect, term scope, and starting date to preview this academic decision.',
        $effect === \App\Models\AcademicDecision::EffectAdvisingRequired => 'The student must meet with the Registrar to review subject selection for this scope.',
        $effect === \App\Models\AcademicDecision::EffectAllowed => 'Academic standing permits enrollment for this scope; standard enrollment clearances apply.',
        $effect === \App\Models\AcademicDecision::EffectBlocked => 'An academic restriction prevents enrollment for this scope until an authorized resolution is recorded.',
        $effect === \App\Models\AcademicDecision::EffectPendingDecision => 'An official academic decision remains pending for this scope.',
        default => 'Choose an enrollment effect, term scope, and starting date to preview this academic decision.',
    };
@endphp

<div class="tala-decision-context-layout">
    {{-- Reused canonical student identity component --}}
    <x-record-identity
        :name="$studentName ?: 'Student'"
        :number="$student->student_number"
        :program="$programName"
        :initials="$initials ?: 'SP'"
        :photo-url="$photoUrl"
        heading-level="h3"
    />

    {{-- About this decision --}}
    <div>
        <h4 class="tala-decision-about-heading text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
            About this decision
        </h4>

        {{-- Dynamic Preview Card --}}
        <div @class([
            'rounded-lg p-3.5 border transition-colors',
            'border-primary-500/30 bg-primary-500/10 dark:bg-primary-500/20 text-primary-950 dark:text-primary-100' => $hasSelection,
            'border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 text-gray-700 dark:text-gray-300' => ! $hasSelection,
        ])>
            <div class="flex items-start gap-2.5">
                <x-filament::icon
                    :icon="$hasSelection ? 'heroicon-s-information-circle' : 'heroicon-o-information-circle'"
                    @class([
                        'w-5 h-5 shrink-0 mt-0.5',
                        'text-primary-600 dark:text-primary-400' => $hasSelection,
                        'text-gray-400' => ! $hasSelection,
                    ])
                />
                <div class="min-w-0 flex-1">
                    <p @class([
                        'text-sm font-semibold leading-snug',
                        'text-primary-950 dark:text-primary-100' => $hasSelection,
                        'text-gray-900 dark:text-white' => ! $hasSelection,
                    ])>
                        {{ $headline }}
                    </p>
                    <p @class([
                        'tala-decision-preview-desc text-xs mt-1 leading-relaxed',
                        'text-primary-900/90 dark:text-primary-200/90' => $hasSelection,
                        'text-gray-500 dark:text-gray-400' => ! $hasSelection,
                    ])>
                        {{ $description }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Earlier decisions reassurance --}}
        <div class="flex items-center gap-2 mt-3 text-xs text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-o-clock" class="w-4 h-4 text-gray-400 shrink-0" />
            <span>Earlier decisions stay in history.</span>
        </div>
    </div>
</div>
