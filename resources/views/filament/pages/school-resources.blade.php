<x-filament-panels::page class="tala-term-workspace tala-resource-workspace">
    <div class="space-y-4">
        @if ($readOnly)
            <x-tala-access-alert variant="info" title="Read-only oversight view">
                <p>Academic Head access is read-only. Registrar remains the recording authority for campus rooms and faculty subject qualifications.</p>
            </x-tala-access-alert>
        @endif

        <section class="tala-task-work" aria-label="School resources">
            <div class="tala-work-tabs tala-work-tabs--full">
                <x-tala-navigation label="School resources view" compact stretch class="tala-work-tabs__nav">
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\SchoolResources::getUrl(['tab' => 'rooms', ...($termId ? ['termId' => $termId] : [])])"
                        :active="$tab === 'rooms'"
                        icon="heroicon-o-building-office-2"
                        wire:click.prevent="chooseTab('rooms')"
                        wire:loading.attr="disabled"
                        wire:target="chooseTab"
                    >
                        Rooms
                    </x-filament::tabs.item>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\SchoolResources::getUrl(['tab' => 'faculty', ...($termId ? ['termId' => $termId] : [])])"
                        :active="$tab === 'faculty'"
                        icon="heroicon-o-academic-cap"
                        wire:click.prevent="chooseTab('faculty')"
                        wire:loading.attr="disabled"
                        wire:target="chooseTab"
                    >
                        Qualified faculty
                    </x-filament::tabs.item>
                </x-tala-navigation>
            </div>

            @if ($tab === 'faculty')
                <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="school-faculty-summary-heading">
                    <div class="tala-resource-readiness-bar__row">
                        <div class="tala-resource-readiness-bar__main">
                            <div class="tala-resource-readiness-bar__headline">
                                <h2 id="school-faculty-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                    <strong class="tala-resource-readiness-bar__count">{{ $qualifiedFacultyCount }} of {{ $activeFacultyCount }}</strong>
                                    <span>active {{ \Illuminate\Support\Str::plural('faculty member', $activeFacultyCount) }} qualified</span>
                                </h2>
                                <span class="tala-resource-readiness-bar__breakdown">· Shared across every term</span>
                            </div>
                        </div>
                    </div>
                </section>
            @else
                <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="school-rooms-summary-heading">
                    <div class="tala-resource-readiness-bar__row">
                        <div class="tala-resource-readiness-bar__main">
                            <div class="tala-resource-readiness-bar__headline">
                                <h2 id="school-rooms-summary-heading" class="tala-resource-readiness-bar__heading tabular-nums">
                                    <strong class="tala-resource-readiness-bar__count">{{ $activeRoomCount }}</strong>
                                    <span>active {{ \Illuminate\Support\Str::plural('room', $activeRoomCount) }}</span>
                                </h2>
                                <span class="tala-resource-readiness-bar__breakdown tabular-nums">
                                    @if ($totalRoomCount > $activeRoomCount)
                                        ({{ $totalRoomCount }} total in catalog) ·
                                    @else
                                        ·
                                    @endif
                                    Shared across every term
                                </span>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{ $this->table }}
        </section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
