<div class="space-y-6" wire:key="calendar-overview-{{ $term->id }}">
    <x-filament::section id="calendar-current">
        <x-slot name="heading">Teaching calendar</x-slot>
        <x-slot name="description">Approved instruction dates and weekly hours determine when classes can meet. Review and activate a Draft before using its changed facts.</x-slot>
        @if ($overviewPackage)
            <div class="tala-planning-next"><div><h3>{{ $overviewPackage->state }} calendar v{{ $overviewPackage->version }}</h3><p>Optional approval: {{ $overviewPackage->authority_reference ?: 'Not recorded' }} · {{ $overviewPackage->authority_date?->format('M j, Y') ?? 'Date not recorded' }}</p></div>
                @if (! $readOnly)<div class="flex flex-wrap gap-3">
                    @if ($draftPackages->isNotEmpty()){{ $this->correctDraftCalendarPackageAction->label('Edit Draft')->color($coreReadiness['ready'] ? 'gray' : 'primary') }} {{ $this->activateCalendarPackageAction->color($coreReadiness['ready'] ? 'primary' : 'gray') }}
                    @elseif ($activePackage){{ $this->recordCalendarPackageAction->color('gray')->outlined() }}
                    @else{{ $this->recordCalendarPackageAction }}@endif
                </div>@endif
            </div>
            <dl class="tala-planning-facts">
                <div><dt>Instruction dates</dt><dd>{{ $overviewPackage->classes_start_on?->format('M j, Y') ?? 'Not recorded' }} – {{ $overviewPackage->classes_end_on?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                <div><dt>Term administration</dt><dd>{{ $overviewPackage->administrative_starts_on?->format('M j, Y') ?? 'Not recorded' }} – {{ $overviewPackage->administrative_ends_on?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
            </dl>
            <h3 class="mt-5 font-semibold">Weekly teaching hours</h3>
            <div class="mt-2 overflow-x-auto"><table class="tala-planning-table"><thead><tr><th>Day</th><th>Teaching hours</th><th>Breaks</th></tr></thead><tbody>
                @forelse ($overviewPackage->teachingGridRows->sortBy('day_of_week') as $row)<tr><th scope="row">{{ \App\Models\SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day' }}</th><td>{{ substr($row->starts_at, 0, 5) }} – {{ substr($row->ends_at, 0, 5) }}</td><td>@forelse ($row->breaks ?? [] as $break){{ substr($break['starts_at'] ?? '', 0, 5) }} – {{ substr($break['ends_at'] ?? '', 0, 5) }}@if (! $loop->last), @endif @empty None recorded @endforelse</td></tr>@empty<tr><td colspan="3">No approved teaching days recorded.</td></tr>@endforelse
            </tbody></table></div>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Scheduling uses fixed 30-minute slots within these approved hours.</p>
            @if ($term->type === \App\Models\Term::TypeSpecialTerm)<p class="mt-3 text-sm">Special Term basis: {{ $overviewPackage->special_term_schedule_basis ?: 'Not recorded' }}</p>@endif
            @if ($overviewPackage->datedExceptions->isNotEmpty())<h3 class="mt-5 font-semibold">Dated teaching exceptions</h3><ul class="mt-2 space-y-2 text-sm">@foreach ($overviewPackage->datedExceptions as $exception)<li>{{ $exception->starts_on->format('M j') }} – {{ $exception->ends_on->format('M j, Y') }} · {{ $exception->label }} · {{ $exception->blocks_teaching ? 'No classes' : $exception->exception_type }} · {{ $exception->authority_reference }}</li>@endforeach</ul>@endif
            @if (! $coreReadiness['ready'])<ul class="mt-5 space-y-3 text-sm" role="status">@foreach ($coreReadiness['blockers'] as $blocker)<li><strong>{{ $blocker['reason'] }}</strong><p>{{ $blocker['next_action'] }}</p></li>@endforeach</ul>
            @elseif ($overviewPackage->state === 'Draft')<p class="mt-4 text-sm" role="status">Core checks pass. Review the facts above, then activate the Draft.</p>
            @elseif ($activePackage)<div class="mt-5"><x-filament::button tag="a" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => 'classes'])">Continue to Classes</x-filament::button></div>@endif
        @else
            <p class="text-sm">Start with the approved instruction dates and teaching days. Enrollment and grading dates can be recorded later.</p>
            @if (! $readOnly)<div class="mt-4">{{ $this->recordCalendarPackageAction }}</div>@endif
        @endif
        @if ($readOnly)<p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Registrar records, corrects and activates the calendar. This is read-only oversight.</p>@endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Enrollment and grading dates</x-slot>
        <x-slot name="description">These dates govern the named activity. A missing date blocks that activity, rather than teaching-calendar activation.</x-slot>
        <div class="overflow-x-auto"><table class="tala-planning-table"><thead><tr><th>Activity</th><th>Approved period</th><th>Current state</th></tr></thead><tbody>
            @foreach ($operationalDates as $item)<tr><th scope="row">{{ $item['label'] }}<span>{{ match ($item['type']) { 'ExaminationPeriod' => 'Information only', default => 'Registrar maintains the approved dates' } }}</span></th><td>@if ($item['window']){{ $item['window']->opens_on->format('M j') }} – {{ $item['window']->closes_on->format('M j, Y') }}@if ($item['window']->cutoff_at)<span>Closes {{ \Carbon\CarbonImmutable::parse($item['window']->cutoff_at)->format('g:i A') }}</span>@endif @else Not configured @endif</td><td>{{ $item['status'] }}</td></tr>@endforeach
        </tbody></table></div>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Asia/Manila · {{ now()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}. Without a closing time, the whole closing day is included.</p>
        @if (! $readOnly && $activePackage)<div class="mt-4">@if ($draftPackages->isEmpty()){{ $this->addOperationalDatesAction->color('gray') }} @else<x-filament::link href="#calendar-current">Edit the pending Draft above to update dates</x-filament::link>@endif</div>@endif
    </x-filament::section>
    @if ($calendarHistory->isNotEmpty())<details class="tala-planning-details"><summary>Calendar history · {{ $calendarHistory->count() }} versions</summary><ul class="mt-3 space-y-3 text-sm">@foreach ($calendarHistory as $package)<li>v{{ $package->version }} · {{ $package->state }} · {{ $package->authority_reference ?: 'Approval not recorded' }}<span class="block text-gray-600 dark:text-gray-300">Recorded by {{ $calendarRecorders[$package->recorded_by] ?? 'Registrar' }} · instruction {{ $package->classes_start_on?->format('M j, Y') }} – {{ $package->classes_end_on?->format('M j, Y') }}</span></li>@endforeach</ul></details>@endif
</div>
