<x-filament-panels::page>
    <x-filament-actions::modals />
    <div class="tala-faculty-workflow space-y-4">
        @if (! $term)
            @include('filament.components.faculty-term-context', ['emptyHeading' => 'Choose a Term to view your availability', 'emptyDescription' => 'Select an Academic Year and Term to see its request and your response.'])
        @else
        <div class="tala-faculty-work-header">@include('filament.components.faculty-term-context', ['contextStatus' => $availabilityRequested && ! $currentDeclaration ? 'Availability requested — submit your response' : null])
        </div>
        <section class="tala-availability-answer tala-task-work" aria-label="Current availability">
            <div class="tala-task-heading"><div>
                @if ($currentDeclaration)
                    <h2>{{ $currentDeclaration->declaration === \App\Models\FacultyAvailabilityDeclaration::DeclarationAvailable ? 'Can teach this Term' : 'Cannot teach this Term' }}</h2>
                    <p>Recorded {{ \App\Support\DisplayDateTime::format($currentDeclaration->declared_at) }} · Asia/Manila</p>
                @elseif ($availabilityRequested)
                    <h2>Your availability is requested</h2>
                    <p>Tell the Registrar whether you can teach and add any weekly times you cannot teach.</p>
                @else
                    <h2>No availability request for this Term</h2>
                    <p>Contact the Registrar to request availability for this exact Term before submitting your first response.</p>
                @endif
                @if ($availabilityRequested || $currentDeclaration)
                    <p class="tala-availability-due"><strong>Response due:</strong> {{ $availabilityDueAt?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') ?? 'Unavailable — contact the Registrar' }} · Asia/Manila</p>
                @endif
            </div>@if ($availabilityRequested || $currentDeclaration){{ $this->declareAvailabilityAction }}@endif</div>
            @if ($currentDeclaration)
                @if ($currentDeclaration->declaration === \App\Models\FacultyAvailabilityDeclaration::DeclarationAvailable)
                    <div class="tala-availability-weekly">
                        <h3>Weekly unavailable times</h3>
                        @if ($currentDeclaration->hard_unavailability === [])
                            <p>No recurring restrictions recorded.</p>
                        @else
                            <ul>
                                @foreach ($weeklyUnavailableTimes as $interval)
                                    <li><strong>{{ $interval['day'] }}</strong><span>{{ $interval['time'] }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @else
                    <p class="tala-availability-whole-term">Unavailable for the whole Term. Current official meetings remain unchanged until the Registrar publishes a validated successor.</p>
                @endif
            @endif
            @if ($hasPublishedImpact)<p role="status" class="mt-3 text-sm"><strong>Official timetable review required.</strong> Your latest answer conflicts with a current assignment. Registrar must publish a validated successor; current meetings remain official. <x-filament::link :href="\App\Filament\Pages\FacultySchedule::getUrl(['termId' => $term->id])">View official schedule</x-filament::link></p>@endif
            @if ($availabilityRequested || $currentDeclaration)
                <details class="mt-3 text-sm"><summary>Request and response source</summary><p>{{ $availabilityDeadlineSource ?: 'Deadline source unavailable' }} · Registrar</p>@if ($currentDeclaration)<p>Response v{{ $currentDeclaration->version }} · {{ \App\Support\DisplayDateTime::format($currentDeclaration->declared_at) }}</p>@endif<p>Availability updates help the Registrar plan; current official meetings remain unchanged.</p></details>
            @endif
        </section>
        @if ($hasDeclaration)
            <details class="tala-availability-history"><summary>Availability history</summary>{{ $this->table }}@if ($hasHistoricalBlocks)<p class="mt-3 text-sm"><x-filament::link :href="$historicalBlocksUrl">Older unavailable-time records</x-filament::link></p>@endif</details>
        @elseif ($hasHistoricalBlocks)
            <p><x-filament::link :href="$historicalBlocksUrl">Older unavailable-time records</x-filament::link></p>
        @endif
        @endif
    </div>
</x-filament-panels::page>
