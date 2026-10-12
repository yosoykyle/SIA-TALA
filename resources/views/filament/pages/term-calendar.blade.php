<x-filament-panels::page>

    @include('filament.pages.partials.term-workspace-header')

    @if ($term)

        <div class="tala-calendar-specification">
        <section class="tala-calendar-work tala-task-work" aria-label="Teaching calendar">

            <h2 class="text-lg font-semibold">Teaching calendar{{ $overviewPackage ? ' · '.$overviewPackage->state.' v'.$overviewPackage->version : '' }}</h2>
            @if ($overviewPackage && $overviewPackage->state === \App\Models\TermCalendarPackage::StateActive)
                <p class="text-sm font-medium text-emerald-600 dark:text-emerald-400">Calendar active</p>
            @endif

            @if ($overviewPackage)

                <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="font-semibold">{{ $overviewPackage->classes_start_on?->format('M j, Y') ?? 'Start date missing' }} – {{ $overviewPackage->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</p><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Approval: {{ $overviewPackage->authority_reference ?: 'Not recorded' }} · {{ $overviewPackage->authority_date?->format('M j, Y') ?? 'Date missing' }}</p></div>

                @if (! $readOnly)<div class="flex flex-wrap gap-2">@if ($draftPackages->isNotEmpty()){{ $this->correctDraftCalendarPackageAction->label('Edit draft') }} {{ $this->activateCalendarPackageAction }}@else{{ $this->recordCalendarPackageAction->label('Change teaching calendar')->color('gray') }}@endif</div>@endif</div>

                <div class="mt-5">{{ $this->table }}</div><p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Meetings use 30-minute slots within these approved hours.</p>

                @if ($overviewPackage->datedExceptions->isNotEmpty())<h3 class="mt-5 font-semibold">No-class days and exceptions</h3><ul class="mt-2 space-y-2 text-sm">@foreach ($overviewPackage->datedExceptions as $exception)<li>{{ $exception->starts_on->format('M j') }} – {{ $exception->ends_on->format('M j, Y') }} · {{ $exception->label }} · {{ $exception->blocks_teaching ? 'No classes' : $exception->exception_type }}</li>@endforeach</ul>@endif

                @if (! $coreReadiness['ready'])<ul class="mt-4 space-y-2 text-sm" role="status">@foreach ($coreReadiness['blockers'] as $blocker)<li><strong>{{ $blocker['reason'] }}</strong> {{ $readOnly ? 'Responsible: '.$blocker['owner'] : $blocker['next_action'] }}</li>@endforeach</ul>@elseif ($draftPackages->isNotEmpty())<p class="mt-4 text-sm" role="status">{{ $readOnly ? 'A Draft is recorded for Registrar review. The existing active calendar remains in use until activation.' : 'Review the draft above, then activate it. The existing active calendar remains in use until activation.' }}</p>@endif

                @if ($term->type === \App\Models\Term::TypeSpecialTerm)<p class="mt-4 text-sm">Special Term basis: {{ $overviewPackage->special_term_schedule_basis ?: 'Not recorded' }}</p>@endif

            @else

                <p class="text-sm">{{ $readOnly ? 'No teaching-calendar source is recorded. Registrar records the approved instruction dates, days and hours.' : 'Record the approved instruction dates, days and hours to begin. Enrollment and grading dates can follow.' }}</p>@if (! $readOnly)<div class="mt-4">{{ $this->recordCalendarPackageAction }}</div>@endif

            @endif

        </section>

        <section class="tala-calendar-dates tala-task-work" aria-label="Activity dates">

            <h2 class="mb-3 text-lg font-semibold">Activity dates</h2><p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Each activity uses its own dates. Examination dates are informational. Activity dates do not gate class planning.</p>

            <dl class="space-y-3 text-sm">
                @foreach ($operationalDates as $item)
                    <div class="border-b border-gray-200 pb-3 dark:border-white/10">
                        <dt class="font-semibold">{{ $item['label'] }}</dt>
                        <dd class="mt-1">
                            @if ($item['window'])
                                {{ $item['window']->opens_on->format('M j') }} – {{ $item['window']->closes_on->format('M j, Y') }} · {{ $item['status'] }}@if ($item['window']->cutoff_at) · {{ $item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod ? 'ends' : 'closes' }} {{ \Carbon\CarbonImmutable::parse($item['window']->cutoff_at)->format('g:i A') }}@endif
                            @else
                                Not configured · {{ match ($item['type']) {
                                    'Enrollment' => 'Ordinary enrollment unavailable',
                                    'LateEnrollment' => 'Ordinary late enrollment unavailable',
                                    'EnrollmentAdjustment' => 'Ordinary adjustment unavailable',
                                    'CourseDrop' => 'Ordinary course drop unavailable',
                                    'GradeEntry' => 'Ordinary Faculty grade entry unavailable',
                                    default => 'Examination information unavailable',
                                } }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($operationalDates->contains(fn (array $item): bool => ! $item['window']))
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Registrar records approved dates{{ $draftPackages->isNotEmpty() ? ' through Edit draft in Teaching calendar.' : ($activePackage ? ' through Update dates below, then activates the draft.' : ' after recording the teaching calendar.') }}</p>
            @endif

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">Asia/Manila · {{ now()->timezone('Asia/Manila')->format('M j, Y · g:i A') }}. Without a closing time, the whole closing day is included.</p>

            @if (! $readOnly && $activePackage)<div class="mt-4">@if ($draftPackages->isEmpty()){{ $this->addOperationalDatesAction->label('Update dates') }}@endif</div>@endif

        </section>
        </div>

    @else <p>Choose a Term above to view its teaching calendar.</p>@endif

    <x-filament-actions::modals />

</x-filament-panels::page>

