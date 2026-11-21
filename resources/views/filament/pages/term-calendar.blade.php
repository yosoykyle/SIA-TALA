<x-filament-panels::page class="tala-term-workspace tala-guide-calendar">
    <x-filament-actions::modals />
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="teaching-calendar-heading">
            @if ($overviewPackage)
                @php
                    $packageStateColor = match ($overviewPackage->state) {
                        \App\Models\TermCalendarPackage::StateActive => 'success',
                        \App\Models\TermCalendarPackage::StateDraft => 'warning',
                        default => 'gray',
                    };
                @endphp
                <header class="tala-calendar-sheet-heading">
                    <h2 id="teaching-calendar-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Classes run</h2>
                    <p><x-filament::badge :color="$packageStateColor">{{ $overviewPackage->state }}</x-filament::badge>@if ($overviewPackage->updated_at)<span>Updated {{ $overviewPackage->updated_at->timezone(config('app.display_timezone'))->format('M j, Y') }}</span>@endif</p>
                </header>
                <div class="tala-calendar-date-range">
                    <dl><dt>Starts</dt><dd><time datetime="{{ $overviewPackage->classes_start_on?->toDateString() }}">{{ $overviewPackage->classes_start_on?->format('M j, Y') ?? 'Start date missing' }}</time></dd></dl>
                    <span class="tala-calendar-date-connector"><span class="sr-only">to</span><x-filament::icon icon="heroicon-o-arrow-long-right" /></span>
                    <dl><dt>Ends</dt><dd><time datetime="{{ $overviewPackage->classes_end_on?->toDateString() }}">{{ $overviewPackage->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</time></dd></dl>
                </div>
                @include('filament.pages.partials.term-teaching-week', ['teachingWeekRows' => $this->teachingWeekRows($overviewPackage)])
                @php
                    $teachingDayChips = $this->teachingDayChips($overviewPackage);
                    $calendarMeta = implode(' · ', array_filter([
                        '30-minute scheduling slots',
                        $overviewPackage->authority_reference ? 'Approval note: '.$overviewPackage->authority_reference : null,
                        $overviewPackage->authority_date ? 'Approved '.$overviewPackage->authority_date->format('M j, Y') : null,
                        $term->type === \App\Models\Term::TypeSpecialTerm ? 'Special Term basis: '.($overviewPackage->special_term_schedule_basis ?: 'Not recorded') : null,
                    ]));
                @endphp
                @if ($teachingDayChips !== [])
                    <ul class="tala-availability-readonly-list mt-4 tabular-nums" aria-label="Daily teaching hours">
                        @foreach ($teachingDayChips as $dayChip)
                            <li>
                                <span class="font-semibold">{{ $dayChip['short_day'] }}</span>
                                <span class="text-right">
                                    <span class="whitespace-nowrap">{{ $dayChip['hours'] }}</span>
                                    @if ($dayChip['breaks'])
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">Break {{ $dayChip['breaks'] }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (! $coreReadiness['ready'])
                    <div class="tala-task-finding" role="status"><ul>@foreach ($coreReadiness['blockers'] as $blocker)<li><strong>{{ $blocker['reason'] }}</strong> {{ $readOnly ? 'Responsible: '.$blocker['owner'] : $blocker['next_action'] }}</li>@endforeach</ul></div>
                @elseif ($draftPackages->isNotEmpty())
                    <p class="tala-work-footnote" role="status">Review and activate this draft when ready. The active calendar stays in use until then.</p>
                @elseif ($activePackage)
                    <p class="tala-calendar-ready"><x-filament::icon icon="heroicon-o-check-circle" />Calendar ready for class planning.</p>
                @endif
                @if ($overviewPackage->datedExceptions->isNotEmpty())
                    <div class="tala-calendar-exceptions"><h3>No-class days and exceptions</h3><ul>@foreach ($overviewPackage->datedExceptions as $exception)<li>{{ $exception->starts_on->format('M j') }} – {{ $exception->ends_on->format('M j, Y') }} · {{ $exception->label }} · {{ $exception->blocks_teaching ? 'No classes' : $exception->exception_type }}</li>@endforeach</ul></div>
                @endif
                <p class="tala-work-footnote mt-4 border-t border-gray-200 pt-3 dark:border-white/10">{{ $calendarMeta }}</p>
            @else
                <div class="tala-calendar-first-use" id="teaching-calendar-heading">
                    <x-servitech-empty-state illustrated heading="Set the teaching week" description="Pre-filled from Term dates ({{ $term->starts_on->format('M j, Y') }} – {{ $term->ends_on->format('M j, Y') }}) and standard Mon–Sat 7:00 AM–7:00 PM teaching hours. Save & activate in one step." />
                </div>
            @endif
            @if (! $readOnly)
                <div class="tala-calendar-commands">
                    {{ $this->saveAndActivateCalendarAction }}
                </div>
            @endif
        </section>
        <section class="tala-task-surface tala-calendar-activities" aria-labelledby="activity-dates-heading">
            <header class="tala-calendar-sheet-heading">
                <h2 id="activity-dates-heading"><x-filament::icon icon="heroicon-o-calendar" />Activity dates</h2>
                @if (! $readOnly && $activePackage && $draftPackages->isEmpty()){{ $this->addOperationalDatesAction->label('Update activity dates')->color('gray')->icon('heroicon-o-pencil-square') }}@endif
            </header>
            <dl class="tala-calendar-activity-list">
                @foreach ($operationalDates as $item)
                    @php($activityIcon = match ($item['type']) {
                        \App\Models\TermCalendarWindow::TypeEnrollment => 'heroicon-o-clipboard-document-check',
                        \App\Models\TermCalendarWindow::TypeLateEnrollment => 'heroicon-o-clock',
                        \App\Models\TermCalendarWindow::TypeEnrollmentAdjustment => 'heroicon-o-arrows-right-left',
                        \App\Models\TermCalendarWindow::TypeCourseDrop => 'heroicon-o-minus-circle',
                        \App\Models\TermCalendarWindow::TypeExaminationPeriod => 'heroicon-o-academic-cap',
                        default => 'heroicon-o-pencil-square',
                    })
                    <div class="tala-calendar-activity {{ $item['window'] ? 'is-recorded' : 'is-unset' }}">
                        <dt><x-filament::icon :icon="$activityIcon" /><span>{{ $item['label'] }}@if ($item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod)<small>Information only</small>@endif</span></dt>
                        <dd>
                            @if ($item['window'])
                                <div class="tala-calendar-activity-period">
                                    <strong><time datetime="{{ $item['window']->opens_on->toDateString() }}">{{ $item['window']->opens_on->format('M j') }}</time> – <time datetime="{{ $item['window']->closes_on->toDateString() }}">{{ $item['window']->closes_on->format('M j, Y') }}</time></strong>
                                    @if ($item['window']->cutoff_at)<small>{{ $item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod ? 'Ends' : 'Closes' }} {{ \Carbon\CarbonImmutable::parse($item['window']->cutoff_at)->format('g:i A') }} · Asia/Manila</small>@endif
                                </div>
                                <x-filament::badge :color="$item['status'] === 'Open' ? 'success' : 'gray'">{{ $item['status'] }}</x-filament::badge>
                            @else
                                <span class="tala-calendar-unset">Dates not configured</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            <p class="tala-work-footnote mt-4 border-t border-gray-200 pt-3 dark:border-white/10">Times use Asia/Manila. Activity dates do not block class planning.</p>
        </section>
    @else
        <p>Choose a Term above to view its teaching calendar.</p>
    @endif
</x-filament-panels::page>
