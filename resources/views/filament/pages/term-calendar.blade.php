<x-filament-panels::page class="tala-term-workspace tala-guide-calendar">
    <x-filament-actions::modals />
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @if ($overviewPackage)
            <section class="tala-task-work tala-calendar-core tala-guide-calendar-core" aria-labelledby="teaching-calendar-heading">
                <p class="tala-calendar-range tala-guide-calendar-range">
                    <span id="teaching-calendar-heading">Classes run</span>
                    <strong>{{ $overviewPackage->classes_start_on?->format('M j, Y') ?? 'Start date missing' }} to {{ $overviewPackage->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</strong>
                    <small>{{ $overviewPackage->state }} · Version {{ $overviewPackage->version }}</small>
                </p>

                <section class="tala-teaching-week" aria-labelledby="teaching-week-heading">
                    <div class="tala-guide-calendar-section-heading">
                        <div>
                            <h3 id="teaching-week-heading">Weekly teaching hours</h3>
                            <p>Recorded weekday hours and breaks</p>
                        </div>
                    </div>
                    @forelse (collect($this->teachingWeekRows($overviewPackage))->groupBy(fn (array $row): string => json_encode($row['segments'])) as $days)
                        @php
                            $day = $days->first();
                            $dayNames = $days->pluck('day')->all();
                            $dayLabel = $dayNames === ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] ? 'Monday to Friday' : implode(', ', $dayNames);
                        @endphp
                        <div class="tala-teaching-day" aria-label="{{ $dayLabel }}: {{ $day['summary'] }}">
                            <h4>{{ $dayLabel }}</h4>
                            <div class="tala-teaching-day-track tala-teaching-segments" role="img" aria-label="{{ $dayLabel }} teaching hours: {{ $day['summary'] }}">
                                @foreach ($day['segments'] as $segment)
                                    <div
                                        class="tala-teaching-segment tala-teaching-{{ $segment['type'] }}"
                                        style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%;"
                                        aria-label="{{ $segment['label'] }}: {{ $segment['time'] }}"
                                        title="{{ $segment['label'] }}: {{ $segment['time'] }}"
                                    >
                                        <span>{{ $segment['label'] }}</span>
                                        <strong>{{ $segment['time'] }}</strong>
                                    </div>
                                @endforeach
                            </div>
                            <p class="tala-teaching-day-summary">{{ $day['summary'] }}</p>
                        </div>
                    @empty
                        <p class="tala-task-finding" role="status">No teaching days are recorded for this calendar.</p>
                    @endforelse
                </section>

                @if (! $coreReadiness['ready'])
                    <div class="tala-task-finding" role="status"><ul>@foreach ($coreReadiness['blockers'] as $blocker)<li><strong>{{ $blocker['reason'] }}</strong> {{ $readOnly ? 'Responsible: '.$blocker['owner'] : $blocker['next_action'] }}</li>@endforeach</ul></div>
                @elseif ($draftPackages->isNotEmpty())
                    <p class="tala-work-footnote" role="status">Review and activate this draft when ready. The active calendar stays in use until then.</p>
                @elseif ($activePackage)
                    <p class="tala-work-footnote">Calendar ready for class planning. Meetings use 30-minute slots within these hours.</p>
                @endif

                @if ($overviewPackage->datedExceptions->isNotEmpty())
                    <div class="tala-calendar-exceptions"><h3>No-class days and exceptions</h3><ul>@foreach ($overviewPackage->datedExceptions as $exception)<li>{{ $exception->starts_on->format('M j') }} – {{ $exception->ends_on->format('M j, Y') }} · {{ $exception->label }} · {{ $exception->blocks_teaching ? 'No classes' : $exception->exception_type }}</li>@endforeach</ul></div>
                @endif

                <details class="tala-planning-details tala-guide-calendar-source">
                    <summary>Teaching hours, source & approval</summary>
                    {{ $this->table }}
                    <p>Approval reference: {{ $overviewPackage->authority_reference ?: 'Not recorded (optional)' }} · {{ $overviewPackage->authority_date?->format('M j, Y') ?? 'No approval date recorded' }}</p>
                    @if ($term->type === \App\Models\Term::TypeSpecialTerm)<p>Special Term basis: {{ $overviewPackage->special_term_schedule_basis ?: 'Not recorded' }}</p>@endif
                    <p>Changes create a new draft and preserve the active calendar’s history.</p>
                </details>
            </section>
        @else
            <section class="tala-task-work tala-guide-calendar-empty" aria-labelledby="teaching-calendar-heading">
                <div class="tala-task-heading">
                    <div>
                        <h2 id="teaching-calendar-heading">Set the teaching week</h2>
                        <p>Record approved class dates and weekly teaching hours to prepare this Term.</p>
                    </div>
                </div>
            </section>
        @endif

        <section class="tala-calendar-activities tala-guide-calendar-activities" aria-labelledby="activity-dates-heading">
            <div class="tala-guide-calendar-section-heading">
                <div>
                    <h3 id="activity-dates-heading">
                        <x-filament::icon icon="heroicon-o-information-circle" class="tala-guide-activity-icon" aria-hidden="true" />
                        Activity dates
                    </h3>
                    <p>Each date applies only to its named activity. These dates do not block class planning.</p>
                </div>
                @if (! $readOnly && $activePackage && $draftPackages->isEmpty()){{ $this->addOperationalDatesAction->label('Update activity dates')->color('gray') }}@endif
            </div>
            <dl class="tala-activity-register">
                @foreach ($operationalDates as $item)
                    <div>
                        <dt>{{ $item['label'] }}@if ($item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod)<small>Information only</small>@endif</dt>
                        <dd>@if ($item['window'])
                            <strong>{{ $item['window']->opens_on->format('M j') }} – {{ $item['window']->closes_on->format('M j, Y') }}</strong>
                            <span>{{ $item['status'] }}@if ($item['window']->cutoff_at) · {{ $item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod ? 'Ends' : 'Closes' }} {{ \Carbon\CarbonImmutable::parse($item['window']->cutoff_at)->format('g:i A') }}@endif</span>
                        @else
                            <strong>Not set</strong>
                            <small>{{ $item['type'] === \App\Models\TermCalendarWindow::TypeExaminationPeriod ? 'Examination information only' : 'Only affects '.strtolower($item['label']) }}</small>
                        @endif</dd>
                    </div>
                @endforeach
            </dl>
            <details class="tala-planning-details tala-guide-calendar-activity-details">
                <summary>About activity dates</summary>
                <p>All times are in Asia/Manila. Without a closing time, the whole closing day is included.</p>
                <p>Enrollment, grading and examination dates apply only to their named activities. Missing activity dates do not block class planning. Examination dates are information only.</p>
            </details>
        </section>

        <footer class="tala-guide-calendar-footer tala-guide-footer">
            @if (! $readOnly)
                @if ($draftPackages->isNotEmpty())
                    <div class="tala-guide-calendar-actions">
                        {{ $this->correctDraftCalendarPackageAction->label('Edit draft')->color('gray') }}
                        {{ $this->activateCalendarPackageAction->label('Review and activate') }}
                    </div>
                @else
                    {{ $this->recordCalendarPackageAction->label($overviewPackage ? 'Change teaching calendar' : 'Record teaching calendar') }}
                @endif
            @endif
        </footer>
    @else
        <p>Choose a Term above to view its teaching calendar.</p>
    @endif
</x-filament-panels::page>
