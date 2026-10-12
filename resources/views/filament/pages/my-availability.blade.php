<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-4">
        <div class="tala-faculty-work-header">@include('filament.components.faculty-term-context', ['contextStatus' => $currentDeclaration ? 'Response recorded · v'.$currentDeclaration->version : ($term ? ($availabilityRequested ? 'Availability requested — submit your response' : 'No availability request for this Term') : null)])
        @include('filament.components.faculty-task-navigation')</div>
        @if ($term)
            <p class="tala-work-footnote">Your availability helps the Registrar plan teaching assignments.</p>
            <section class="tala-availability-answer tala-task-work" aria-label="Current availability">
                <div class="tala-task-heading"><div>
                    @if ($currentDeclaration)<h2>{{ $currentDeclaration->declaration === \App\Models\FacultyAvailabilityDeclaration::DeclarationAvailable ? 'Can teach this Term' : 'Cannot teach this Term' }}</h2>
                        <p class="text-sm">{{ $this->availabilitySummary($currentDeclaration) }}</p>
                    @else<h2>{{ $availabilityRequested ? 'Submit your availability response' : 'Contact the Registrar for an availability request' }}</h2>@endif
                    <p><strong>Response due:</strong> {{ $availabilityDueAt?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') ?? 'Unavailable — contact the Registrar' }} · Asia/Manila</p>
                </div>{{ $this->declareAvailabilityAction }}</div>
                @if (! $availabilityRequested && ! $hasDeclaration)<p class="mt-2 text-sm">Contact the Registrar to request availability for this exact Term before submitting your first response.</p>@endif
                @if ($hasPublishedImpact)<p role="status" class="mt-3 text-sm"><strong>Official timetable review required.</strong> Your latest answer conflicts with a current assignment. Registrar must publish a validated successor; current meetings remain official. <x-filament::link :href="\App\Filament\Pages\FacultySchedule::getUrl(['termId' => $term->id])">View official schedule</x-filament::link></p>@endif
                <details class="mt-3 text-sm"><summary>Current Term request and response source</summary><p>{{ $availabilityDeadlineSource ?: 'Deadline source unavailable' }} · Registrar</p>@if ($currentDeclaration)<p>Response v{{ $currentDeclaration->version }} · {{ \App\Support\DisplayDateTime::format($currentDeclaration->declared_at) }}</p>@endif<p>Availability updates help the Registrar plan; current official meetings remain unchanged.</p></details>
            </section>
            <section class="tala-task-work"><div class="tala-task-heading"><h2>Availability history</h2></div>{{ $this->table }}@if ($hasHistoricalBlocks)<p class="mt-3 text-sm"><x-filament::link :href="$historicalBlocksUrl">Older unavailable-time records</x-filament::link></p>@endif</section>
        @else<p>Select a Term to see its request, due date and your answer.</p>@endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
