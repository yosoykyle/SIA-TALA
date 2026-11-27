<x-filament-panels::page>
    <div class="tala-faculty-workflow">
        @if (! $term)
            <x-servitech-empty-state
                illustrated
                heading="No active term yet"
                description="Your availability opens here once the Registrar creates and activates a term."
                icon="heroicon-o-calendar-days"
            />
        @else
            <x-record-scope-bar class="tala-faculty-term-card"
                label="Active term"
                icon="heroicon-o-calendar-days"
                :scope-title="$scopeTitle"
                :scope-meta="$scopeMeta"
            />

            @if ($registrarNotTeaching ?? false)
                <section class="tala-warning-banner" role="status" aria-labelledby="faculty-registrar-not-teaching-heading">
                    <h2 id="faculty-registrar-not-teaching-heading" class="flex items-center gap-2 text-sm font-semibold">
                        <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" aria-hidden="true" />
                        <span>Marked as not teaching this term by the Registrar</span>
                    </h2>
                    <p class="text-sm">
                        The Registrar set your teaching load to 0 units for this term (Not teaching (Registrar)), so you will not be assigned classes.
                    </p>
                    @if (filled($registrarNotTeachingReason ?? null))
                        <p class="text-sm">
                            <strong>Recorded reason:</strong> {{ $registrarNotTeachingReason }}
                        </p>
                    @endif
                    <p class="text-sm">
                        Contact the Registrar if your teaching status for this term needs to be restored.
                    </p>
                </section>
            @endif

            @if ($isTermClosed ?? false)
                <section class="tala-warning-banner" role="status" aria-labelledby="faculty-closed-term-heading">
                    <h2 id="faculty-closed-term-heading" class="flex items-center gap-2 text-sm font-semibold">
                        <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5" aria-hidden="true" />
                        <span>This term is closed</span>
                    </h2>
                    <p class="text-sm">
                        Availability for a closed term is read-only and can no longer be edited.
                    </p>
                </section>
            @endif

            @if ($needsGridReview)
                <section class="tala-warning-banner" role="status" aria-labelledby="faculty-grid-review-heading">
                    <h2 id="faculty-grid-review-heading" class="flex items-center gap-2 text-sm font-semibold">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                        <span>School teaching hours changed</span>
                    </h2>
                    <p class="text-sm">
                        @if ($canEditAvailability)
                            Adjust the highlighted days below to match the updated school hours.
                        @else
                            Some of your recorded teaching windows fall outside the updated school hours. Contact the Registrar if your availability needs adjustment.
                        @endif
                    </p>
                </section>
            @endif

            @if ($hasPublishedImpact)
                <section class="tala-warning-banner" role="status" aria-labelledby="faculty-official-impact-heading">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <h2 id="faculty-official-impact-heading" class="flex items-center gap-2 text-sm font-semibold">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                                <span>Some current classes conflict with your updated availability</span>
                            </h2>
                            <p class="text-sm">
                                Continue teaching these classes until the Registrar publishes an updated schedule.
                            </p>
                        </div>
                        <x-filament::button tag="a" color="gray" size="sm" :href="\App\Filament\Pages\FacultySchedule::getUrl(['termId' => $term->id])">
                            View my schedule
                        </x-filament::button>
                    </div>
                    @if ($lateImpactMeetings !== [])
                        <ul class="tala-faculty-affected-list mt-1">
                            @foreach ($lateImpactMeetings as $meeting)
                                <li class="tala-faculty-affected-row">
                                    <div class="tala-faculty-affected-row__main">
                                        <strong>{{ collect([$meeting['course_code'] ?? null, $meeting['course_title'] ?? null, $meeting['section_code'] ?? null])->filter()->implode(' · ') ?: 'Official class' }}</strong>
                                        <span class="tabular-nums">{{ $meeting['day_label'] }} · {{ $meeting['time_label'] }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if (! ($canEditAvailability ?? $hasActiveCoreCalendar) && ! $currentDeclaration && ! ($registrarNotTeaching ?? false))
                <x-servitech-empty-state
                    illustrated
                    :heading="($isTermClosed ?? false) ? 'No availability recorded for this closed term' : 'Teaching calendar is not active yet'"
                    :description="($isTermClosed ?? false) ? 'This term is closed, so availability responses can no longer be submitted.' : 'Availability opens as soon as the Registrar activates the teaching calendar for this term.'"
                />
            @else
                <section class="tala-task-surface tala-availability-card" aria-labelledby="faculty-availability-status-heading">
                    <div class="tala-availability-card__body">
                        <header class="tala-availability-header">
                            <div class="tala-availability-header__main">
                                <h2 id="faculty-availability-status-heading">{{ $statusHeading }}</h2>
                                @if ($currentDeclaration)
                                    <p class="tala-availability-header__meta tabular-nums">
                                        {{ $currentDeclaration->version === 1 ? 'Submitted' : 'Updated (v'.$currentDeclaration->version.')' }}
                                        {{ \App\Support\DisplayDateTime::format($currentDeclaration->declared_at) }}
                                    </p>
                                @elseif ($registrarNotTeaching ?? false)
                                    <p class="tala-availability-header__meta">
                                        Read-only while the Registrar has you marked as not teaching this term.
                                    </p>
                                @else
                                    <p class="tala-availability-header__meta">
                                        Choose how you can teach this term.
                                    </p>
                                @endif
                            </div>
                            @if ($weeklyHoursSummary || ! empty($hoursTarget))
                                <div class="tala-availability-header__metrics">
                                    @if ($weeklyHoursSummary)
                                        <p class="tala-availability-hours-pill tabular-nums" role="status" aria-live="polite">
                                            {{ $weeklyHoursSummary }}
                                        </p>
                                    @endif
                                    @if (! empty($hoursTarget))
                                        @php
                                            $hoursTargetStatus = $hoursTarget['status'] ?? 'neutral';
                                            $hoursTargetIcon = match ($hoursTargetStatus) {
                                                'met' => 'heroicon-m-check-circle',
                                                'short' => 'heroicon-m-exclamation-triangle',
                                                default => 'heroicon-m-clock',
                                            };
                                        @endphp
                                        <p
                                            @class([
                                                'tala-availability-target-pill',
                                                'tabular-nums',
                                                'is-met' => $hoursTargetStatus === 'met',
                                                'is-short' => $hoursTargetStatus === 'short',
                                                'is-neutral' => $hoursTargetStatus === 'neutral',
                                            ])
                                            role="status"
                                            aria-live="polite"
                                        >
                                            <x-filament::icon :icon="$hoursTargetIcon" class="tala-availability-target-pill__icon" aria-hidden="true" />
                                            <span>{{ $hoursTarget['label'] }}</span>
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </header>

                        @if ($groupedApprovedHours !== [])
                            <div class="tala-context-inset tala-availability-school-hours">
                                <div class="tala-availability-school-hours__row">
                                    <strong>School teaching hours:</strong>
                                    <span class="tabular-nums">{{ implode(' · ', $groupedApprovedHours) }}</span>
                                </div>
                                <p class="tala-availability-school-hours__note">
                                    Mandatory breaks are never scheduled even if your selected range spans a break.
                                </p>
                            </div>
                        @endif

                        @if ($effectiveMode === \App\Models\FacultyAvailabilityDeclaration::ModeLegacyUnavailability)
                            <div class="tala-context-inset space-y-2">
                                <h3 class="text-sm font-semibold">Recorded unavailable times</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Recorded under the earlier unavailable-times format.
                                </p>
                                @if ($legacyUnavailableTimes->isEmpty())
                                    <p class="text-sm">No recurring unavailable times recorded.</p>
                                @else
                                    <ul class="tala-availability-legacy-list tabular-nums">
                                        @foreach ($legacyUnavailableTimes as $interval)
                                            <li><strong>{{ $interval['day'] }}</strong> <span>{{ $interval['time'] }}</span></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif

                        @if ($canEditAvailability)
                            <form wire:submit="saveAvailability" class="tala-availability-form" novalidate>
                                <fieldset class="tala-availability-fieldset">
                                    <legend class="tala-availability-fieldset__legend">How can you teach this term?</legend>
                                    <div class="tala-choice-options fi-fo-radio tala-availability-modes">
                                        <label class="fi-fo-radio-label">
                                            <input
                                                type="radio"
                                                name="availability_mode"
                                                value="{{ \App\Models\FacultyAvailabilityDeclaration::ModeWindows }}"
                                                wire:model.live="availabilityMode"
                                                class="fi-radio-input"
                                            />
                                            <span class="fi-fo-radio-label-text">
                                                <span class="block">Specific weekly times</span>
                                                <span class="block fi-fo-radio-label-description">Pick the days and time ranges you can teach.</span>
                                            </span>
                                        </label>

                                        <label class="fi-fo-radio-label">
                                            <input
                                                type="radio"
                                                name="availability_mode"
                                                value="{{ \App\Models\FacultyAvailabilityDeclaration::ModeAnyApproved }}"
                                                wire:model.live="availabilityMode"
                                                class="fi-radio-input"
                                            />
                                            <span class="fi-fo-radio-label-text">
                                                <span class="block">Any school teaching time</span>
                                                <span class="block fi-fo-radio-label-description">Available across all approved hours; follows grid updates automatically.</span>
                                            </span>
                                        </label>

                                        <label class="fi-fo-radio-label">
                                            <input
                                                type="radio"
                                                name="availability_mode"
                                                value="{{ \App\Models\FacultyAvailabilityDeclaration::ModeCannotTeach }}"
                                                wire:model.live="availabilityMode"
                                                class="fi-radio-input"
                                            />
                                            <span class="fi-fo-radio-label-text">
                                                <span class="block">Unavailable this term</span>
                                                <span class="block fi-fo-radio-label-description">You will not be scheduled for any class this term.</span>
                                            </span>
                                        </label>
                                    </div>
                                    @error('availabilityMode')
                                        <p class="tala-availability-error" role="alert">{{ $message }}</p>
                                    @enderror
                                </fieldset>

                                @if ($availabilityMode === \App\Models\FacultyAvailabilityDeclaration::ModeWindows)
                                    <div class="tala-availability-days" aria-label="Weekly teaching times">
                                        <div class="tala-availability-days__header">
                                            <h3>Weekly teaching times</h3>
                                            <span class="tala-availability-days__hint">Check the days you can teach and adjust hours as needed.</span>
                                        </div>

                                        <ul class="tala-availability-day-list">
                                            @foreach ($editorDayRows as $row)
                                                <li
                                                    wire:key="availability-day-{{ $row['day_of_week'] }}"
                                                    @class([
                                                        'tala-availability-day-row',
                                                        'is-enabled' => $row['is_enabled'],
                                                        'is-outside-grid' => $row['has_warning'],
                                                    ])
                                                >
                                                    <div class="tala-availability-day-row__identity">
                                                        <label class="tala-availability-day-toggle">
                                                            <input
                                                                type="checkbox"
                                                                wire:click="toggleDay({{ $row['day_of_week'] }})"
                                                                @checked($row['is_enabled'])
                                                                @disabled(! $row['is_approved_day'] && ! $row['is_enabled'])
                                                            />
                                                            <span class="tala-availability-day-toggle__name">{{ $row['day_label'] }}</span>
                                                        </label>
                                                        <div class="tala-availability-day-row__bounds tabular-nums">
                                                            <span>{{ $row['hours_label'] }}</span>
                                                            @if ($row['breaks_label'])
                                                                <span>· {{ $row['breaks_label'] }}</span>
                                                            @endif
                                                        </div>
                                                        @if ($row['has_warning'])
                                                            <div class="tala-availability-day-row__warning">
                                                                <x-filament::badge color="warning" size="sm">Outside approved hours</x-filament::badge>
                                                                <span>{{ $row['warning_reason'] }}</span>
                                                            </div>
                                                        @endif
                                                    </div>

                                                    <div class="tala-availability-day-row__controls">
                                                        @if ($row['is_enabled'])
                                                            <div class="tala-availability-ranges">
                                                                @foreach ($row['ranges'] as $range)
                                                                    <div
                                                                        wire:key="availability-day-{{ $row['day_of_week'] }}-range-{{ $range['index'] }}"
                                                                        class="tala-availability-range-row"
                                                                    >
                                                                        <label class="tala-availability-time-field">
                                                                            <span class="sr-only">{{ $row['day_label'] }} start time {{ $range['index'] + 1 }}</span>
                                                                            <select
                                                                                wire:model.live="dayWindows.{{ $row['day_of_week'] }}.{{ $range['index'] }}.start_time"
                                                                                class="fi-select-input tala-availability-select tabular-nums"
                                                                            >
                                                                                @foreach ($range['start_options'] as $value => $label)
                                                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                                                @endforeach
                                                                            </select>
                                                                        </label>
                                                                        <span class="tala-availability-range-sep" aria-hidden="true">–</span>
                                                                        <label class="tala-availability-time-field">
                                                                            <span class="sr-only">{{ $row['day_label'] }} end time {{ $range['index'] + 1 }}</span>
                                                                            <select
                                                                                wire:model.live="dayWindows.{{ $row['day_of_week'] }}.{{ $range['index'] }}.end_time"
                                                                                class="fi-select-input tala-availability-select tabular-nums"
                                                                            >
                                                                                @foreach ($range['end_options'] as $value => $label)
                                                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                                                @endforeach
                                                                            </select>
                                                                        </label>
                                                                        @if (count($row['ranges']) > 1 || ! $row['is_approved_day'])
                                                                            <button
                                                                                type="button"
                                                                                wire:click="removeDayRange({{ $row['day_of_week'] }}, {{ $range['index'] }})"
                                                                                class="tala-availability-icon-btn"
                                                                                aria-label="Remove time range for {{ $row['day_label'] }}"
                                                                            >
                                                                                <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" aria-hidden="true" />
                                                                            </button>
                                                                        @endif
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                            <div class="tala-availability-day-actions">
                                                                @if ($row['is_approved_day'])
                                                                    <button
                                                                        type="button"
                                                                        wire:click="addDayRange({{ $row['day_of_week'] }})"
                                                                        class="tala-availability-inline-btn"
                                                                    >
                                                                        + Add time range
                                                                    </button>
                                                                @endif
                                                                @if ($row['is_weekday'] && $canCopyToWeekdays)
                                                                    <button
                                                                        type="button"
                                                                        wire:click="copyDayToWeekdays({{ $row['day_of_week'] }})"
                                                                        class="tala-availability-inline-btn"
                                                                    >
                                                                        Copy to weekdays
                                                                    </button>
                                                                @endif
                                                            </div>
                                                        @else
                                                            <span class="tala-availability-day-off">Not available</span>
                                                        @endif
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ul>

                                        @if (! empty($breakSplitWarning))
                                            <section class="tala-warning-banner" role="status" aria-labelledby="faculty-break-split-warning-heading">
                                                <h4 id="faculty-break-split-warning-heading" class="flex items-center gap-2 text-sm font-semibold">
                                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                                                    <span>Selected time blocks may be too short for a qualified subject</span>
                                                </h4>
                                                <p class="text-sm">
                                                    After daily breaks, your longest uninterrupted block is <strong class="tabular-nums">{{ $breakSplitWarning['longest_block_label'] }}</strong>, which is shorter than <strong>{{ $breakSplitWarning['subjects_summary'] }}</strong>. Extend a range before or after the break so this class can fit, or save as-is if you do not expect to teach it.
                                                </p>
                                            </section>
                                        @endif

                                        @error('teachingWindows')
                                            <p class="tala-availability-error" role="alert">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @endif

                                <footer class="tala-availability-footer">
                                    <div class="tala-availability-footer__actions">
                                        <x-filament::button type="button" color="gray" wire:click="cancelEdits">
                                            Cancel
                                        </x-filament::button>
                                        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="saveAvailability">
                                            Save availability
                                        </x-filament::button>
                                    </div>
                                </footer>
                            </form>
                        @elseif ($currentDeclaration || ($registrarNotTeaching ?? false))
                            @if (($hasDeclaration ?? false) && ! ($isTermClosed ?? false) && ! ($registrarNotTeaching ?? false))
                                <div class="tala-availability-locked-notice" role="status">
                                    <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5" aria-hidden="true" />
                                    <p>Availability submitted and locked for this term. Contact the Registrar if changes are needed.</p>
                                </div>
                            @endif

                            @if ($currentDeclaration && $effectiveMode === \App\Models\FacultyAvailabilityDeclaration::ModeWindows && $teachingWindows->isNotEmpty())
                                <ul class="tala-availability-readonly-list tabular-nums">
                                    @foreach ($teachingWindows as $window)
                                        <li>
                                            <div class="space-y-1">
                                                <strong>{{ $window['day'] }}</strong>
                                                @if (filled($window['outside_reason'] ?? null))
                                                    <div>
                                                        <x-filament::badge color="warning" size="sm">Outside approved hours</x-filament::badge>
                                                    </div>
                                                @endif
                                            </div>
                                            <span>{{ $window['time'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif ($currentDeclaration && $effectiveMode === \App\Models\FacultyAvailabilityDeclaration::ModeAnyApproved)
                                <p class="tala-work-footnote">
                                    Available across all approved school teaching hours.
                                </p>
                            @elseif ($currentDeclaration && $effectiveMode === \App\Models\FacultyAvailabilityDeclaration::ModeCannotTeach)
                                <p class="tala-work-footnote">
                                    You are marked unavailable for any class this term.
                                </p>
                            @endif

                            @if (filled($currentDeclaration?->correction_reason))
                                <p class="tala-work-footnote">
                                    <strong>Note:</strong> {{ $currentDeclaration->correction_reason }}
                                </p>
                            @endif

                            @if ($isTermClosed ?? false)
                                <p class="tala-work-footnote">
                                    This term is closed. Availability can no longer be edited.
                                </p>
                            @elseif ($registrarNotTeaching ?? false)
                                <p class="tala-work-footnote">
                                    The Registrar marked you as not teaching this term, so availability is read-only.
                                </p>
                            @elseif (! $hasActiveCoreCalendar && ! ($hasDeclaration ?? false))
                                <p class="tala-work-footnote">
                                    This term’s teaching calendar is not currently active. Updates open when the Registrar activates the teaching calendar.
                                </p>
                            @endif
                        @endif
                    </div>
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
