<header class="faculty-class-header">
    <div class="faculty-class-header-navigation">
        <x-filament::breadcrumbs :breadcrumbs="[
            $this::getUrl(['classes' => 1]) => 'Grade rosters',
            collect([$roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code, $roster->section?->code])->filter()->implode(' · ') ?: $heading,
        ]" />
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
        <x-filament::badge color="gray">{{ match ($roster->state) {
            \App\Models\GradeRoster::StateReturned => 'Roster returned for changes',
            \App\Models\GradeRoster::StateReleased => 'Released',
            \App\Models\GradeRoster::StateSubmitted => 'Submitted for Registrar review',
            \App\Models\GradeRoster::StateLateNotSubmitted => 'Submission overdue',
            default => 'Draft',
        } }}</x-filament::badge>
    </div>
    @foreach ($actions as $action)
        @if (! in_array($action->getName(), ['selectRoster', 'printRoster', 'downloadRoster'], true) && $action->isVisible())
            <div>{{ $action }}</div>
        @endif
    @endforeach
</header>
