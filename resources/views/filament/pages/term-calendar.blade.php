<x-filament-panels::page class="tala-term-workspace tala-guide-calendar">
    <x-filament-actions::modals />
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="teaching-calendar-heading">
            @if ($overviewPackage)
                <header class="tala-calendar-sheet-heading">
                    <h2 id="teaching-calendar-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Classes run</h2>
                    <p><x-filament::badge color="gray">{{ $overviewPackage->state }}</x-filament::badge><span>Version {{ $overviewPackage->version }}</span></p>
                </header>
                <div class="tala-calendar-date-range">
                    <dl><dt>Starts</dt><dd><time datetime="{{ $overviewPackage->classes_start_on?->toDateString() }}">{{ $overviewPackage->classes_start_on?->format('M j, Y') ?? 'Start date missing' }}</time></dd></dl>
                    <span class="tala-calendar-date-connector"><span class="sr-only">to</span><x-filament::icon icon="heroicon-o-arrow-long-right" /></span>
                    <dl><dt>Ends</dt><dd><time datetime="{{ $overviewPackage->classes_end_on?->toDateString() }}">{{ $overviewPackage->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</time></dd></dl>
                </div>
                @include('filament.pages.partials.term-teaching-week', ['teachingWeekRows' => $this->teachingWeekRows($overviewPackage)])
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
            @else
                <div class="tala-calendar-first-use">
                    <x-filament::icon icon="heroicon-o-calendar-days" />
                    <h2 id="teaching-calendar-heading">Set the teaching week</h2>
                    <p>Record approved class dates and weekly teaching hours to prepare this Term.</p>
                </div>
            @endif
            @if (! $readOnly)
                <div class="tala-calendar-commands">
                    @if ($draftPackages->isNotEmpty())
                        {{ $this->correctDraftCalendarPackageAction->label('Edit draft')->color('gray')->icon('heroicon-o-pencil-square') }}
                        {{ $this->activateCalendarPackageAction->label('Review and activate')->icon('heroicon-o-check') }}
                    @else
                        {{ $this->recordCalendarPackageAction->label($overviewPackage ? 'Change teaching calendar' : 'Record teaching calendar')->color($overviewPackage ? 'gray' : 'primary')->icon('heroicon-o-pencil-square') }}
                    @endif
                </div>
            @endif
            @if ($overviewPackage)
                <details class="tala-calendar-source">
                    <summary>Teaching hours, source and calendar history</summary>
                    <div class="tala-planning-reveal">
                        <p>Meetings use 30-minute slots within the recorded teaching hours.</p>
                        {{ $this->table }}
                        @if ($overviewPackage->authority_reference || $overviewPackage->authority_date)<p>@if ($overviewPackage->authority_reference)Approval reference: {{ $overviewPackage->authority_reference }}@endif @if ($overviewPackage->authority_date)Approved {{ $overviewPackage->authority_date->format('M j, Y') }}@endif</p>@endif
                        @if ($term->type === \App\Models\Term::TypeSpecialTerm)<p>Special Term basis: {{ $overviewPackage->special_term_schedule_basis ?: 'Not recorded' }}</p>@endif
                        <p>Changes create a new draft and preserve the active calendar’s history.</p>
                    </div>
                </details>
            @endif
        </section>
        <section class="tala-task-surface tala-calendar-activities" aria-labelledby="activity-dates-heading">
            <header class="tala-calendar-sheet-heading">
                <h2 id="activity-dates-heading"><x-filament::icon icon="heroicon-o-calendar" />Activity dates</h2>
                @if (! $readOnly && $activePackage && $draftPackages->isEmpty()){{ $this->addOperationalDatesAction->label('Update activity dates')->color('gray')->icon('heroicon-o-pencil-square') }}@endif
            </header>
            <p class="tala-work-footnote">Enrollment, grading and other activity dates · Separate from the teaching week.</p>
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
            <details class="tala-calendar-source">
                <summary>What these dates control</summary>
                <div class="tala-planning-reveal">
                    <p>Each date applies only to its named activity. These dates do not block class planning.</p>
                    @foreach ($operationalDates as $item)
                        @if (! $item['window'])
                            <p><strong>{{ $item['label'] }}:</strong> {{ match ($item['type']) {
                                \App\Models\TermCalendarWindow::TypeEnrollment => 'Standard registration start is unavailable.',
                                \App\Models\TermCalendarWindow::TypeLateEnrollment => 'The ordinary late-registration period is unavailable.',
                                \App\Models\TermCalendarWindow::TypeEnrollmentAdjustment => 'Ordinary enrollment changes are unavailable.',
                                \App\Models\TermCalendarWindow::TypeCourseDrop => 'Ordinary course drops are unavailable.',
                                \App\Models\TermCalendarWindow::TypeGradeEntry => 'Ordinary grade entry and submission are unavailable.',
                                default => 'Contact Registrar or Faculty for examination information.',
                            } }}</p>
                        @endif
                    @endforeach
                    <p>Times use Asia/Manila. Without a cutoff time, the whole closing day is included. Approved exceptions follow their own authorization.</p>
                </div>
            </details>
        </section>
    @else
        <p>Choose a Term above to view its teaching calendar.</p>
    @endif
</x-filament-panels::page>
