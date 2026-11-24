@php
    $diff = $reviewData['diff'];
    $blockers = $reviewData['blockers'];
    $warnings = $reviewData['warnings'];
    $grand = $reviewData['grand_total'];
@endphp

<section class="tala-review-step" aria-label="Curriculum review and readiness">
    <div class="tala-review-step__header">
        <div>
            <h2>{{ $isEditableDraft ? 'Review and publish' : 'Curriculum summary' }}</h2>
            <p class="tala-review-step__meta">
                {{ $grand['total_count'] }} {{ \Illuminate\Support\Str::plural('subject', $grand['total_count']) }} · {{ $grand['total_units'] }} total units across {{ count($reviewData['summary_rows']) }} {{ \Illuminate\Support\Str::plural('year', count($reviewData['summary_rows'])) }}
            </p>
        </div>
    </div>

    <div class="tala-review-step__body">
        {{-- Issues & Readiness Box --}}
        @if ($isEditableDraft)
            <div class="tala-readiness-stack">
                @if ($blockers !== [])
                    <x-tala-access-alert variant="danger" title="Fix these issues before publishing" role="alert">
                        <ul>
                            @foreach ($blockers as $blocker)
                                <li>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    </x-tala-access-alert>
                @endif

                @if ($warnings !== [])
                    <section class="tala-advisory-card" aria-label="Advisory notices">
                        <header class="tala-advisory-card__header">
                            <div class="tala-advisory-card__title">
                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span>Advisory notices</span>
                                <x-filament::badge color="warning" size="sm" class="tabular-nums">{{ count($warnings) }}</x-filament::badge>
                            </div>
                            <span class="tala-advisory-card__note">Does not block publishing</span>
                        </header>
                        <ul class="tala-advisory-card__list">
                            @foreach ($warnings as $warning)
                                @php
                                    $scope = is_array($warning) ? ($warning['scope'] ?? null) : null;
                                    $message = is_array($warning) ? ($warning['message'] ?? '') : (string) $warning;
                                    $targetYear = is_array($warning) ? ($warning['year'] ?? null) : null;
                                @endphp
                                <li class="tala-advisory-card__row">
                                    <div class="tala-advisory-card__copy">
                                        @if ($scope)
                                            <x-filament::badge color="warning" size="sm">{{ $scope }}</x-filament::badge>
                                        @endif
                                        <span>{{ $message }}</span>
                                    </div>
                                    @if ($targetYear !== null && $canEdit)
                                        <x-filament::button
                                            type="button"
                                            size="xs"
                                            color="gray"
                                            icon="heroicon-m-arrow-right"
                                            icon-position="after"
                                            wire:click="selectYear({{ $targetYear }})"
                                        >
                                            Go to Year {{ $targetYear }}
                                        </x-filament::button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($blockers === [] && $warnings === [])
                    <x-tala-access-alert variant="success" title="Ready to publish">
                        <p>All subjects and scheduling requirements pass readiness checks. Review the summary below and select <strong>Publish curriculum</strong> when ready.</p>
                    </x-tala-access-alert>
                @endif
            </div>
        @endif

        {{-- Per-Year Summary Table --}}
        <x-filament::section
            :compact="true"
            heading="Per-year summary"
            description="Subject counts and credit units by year level and semester."
            class="tala-semester-card"
        >
            <x-data-table label="Per-year summary" class="tala-review-summary-table-wrap">
                <thead>
                    <tr>
                        <th scope="col">Year</th>
                        <th scope="col" class="tala-data-table-number">1st sem (subj / units)</th>
                        <th scope="col" class="tala-data-table-number">2nd sem (subj / units)</th>
                        <th scope="col" class="tala-data-table-number">Summer (subj / units)</th>
                        <th scope="col" class="tala-data-table-number">Year total</th>
                        <th scope="col" class="tala-review-summary-table__action-col">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reviewData['summary_rows'] as $yearRow)
                        <tr wire:key="review-summary-year-{{ $yearRow['year'] }}">
                            <td class="tala-review-summary-table__year">{{ $yearRow['label'] }}</td>
                            <td class="tala-data-table-number">{{ $yearRow['sem1_count'] }} / {{ $yearRow['sem1_units'] }} u</td>
                            <td class="tala-data-table-number">{{ $yearRow['sem2_count'] }} / {{ $yearRow['sem2_units'] }} u</td>
                            <td class="tala-data-table-number">
                                @if ($yearRow['summer_count'] > 0)
                                    {{ $yearRow['summer_count'] }} / {{ $yearRow['summer_units'] }} u
                                @else
                                    —
                                @endif
                            </td>
                            <td class="tala-data-table-number tala-review-summary-table__total">
                                {{ $yearRow['total_count'] }} / {{ $yearRow['total_units'] }} u
                            </td>
                            <td class="tala-review-summary-table__action-col">
                                <x-filament::button
                                    type="button"
                                    size="xs"
                                    color="gray"
                                    wire:click="selectYear({{ $yearRow['year'] }})"
                                >
                                    {{ $canEdit ? 'Edit' : 'View' }}
                                </x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row">Grand total</th>
                        <td class="tala-data-table-number">{{ $grand['sem1_count'] }} / {{ $grand['sem1_units'] }} u</td>
                        <td class="tala-data-table-number">{{ $grand['sem2_count'] }} / {{ $grand['sem2_units'] }} u</td>
                        <td class="tala-data-table-number">
                            @if ($grand['summer_count'] > 0)
                                {{ $grand['summer_count'] }} / {{ $grand['summer_units'] }} u
                            @else
                                —
                            @endif
                        </td>
                        <td class="tala-data-table-number">{{ $grand['total_count'] }} / {{ $grand['total_units'] }} u</td>
                        <td></td>
                    </tr>
                </tfoot>
            </x-data-table>
        </x-filament::section>

        {{-- Full Subject List Grouped by Year & Semester with Diff Badges --}}
        <x-filament::section
            :compact="true"
            heading="Complete subject list"
            :description="$diff['has_source'] ? 'Comparing subjects against '.$diff['source_label'] : 'All subjects placed in this curriculum, grouped by year and semester.'"
            class="tala-semester-card"
        >
            @if ($diff['has_source'])
                <div class="tala-diff-summary-bar">
                    <x-filament::badge size="sm" color="gray" class="tala-tabular">Copied: {{ $diff['counts']['copied'] }}</x-filament::badge>
                    @if (($diff['moved_count'] ?? 0) > 0)
                        <x-filament::badge size="sm" color="info" class="tala-tabular">Moved: {{ $diff['moved_count'] }}</x-filament::badge>
                    @endif
                    <x-filament::badge size="sm" color="warning" class="tala-tabular">Edited: {{ $diff['counts']['edited'] }}</x-filament::badge>
                    <x-filament::badge size="sm" color="success" class="tala-tabular">Added: {{ $diff['counts']['added'] }}</x-filament::badge>
                    <x-filament::badge size="sm" color="danger" class="tala-tabular">Removed: {{ $diff['counts']['removed'] }}</x-filament::badge>
                </div>
            @endif

            @if ($reviewData['grouped_years'] !== [])
                <div class="tala-review-groups">
                    @foreach ($reviewData['grouped_years'] as $yearGroup)
                        <div class="tala-review-year-block" wire:key="review-year-group-{{ $yearGroup['year'] }}">
                            <div class="tala-review-year-block__head">
                                <h3>{{ $yearGroup['label'] }}</h3>
                                <span class="tala-tabular">{{ $yearGroup['total_count'] }} {{ \Illuminate\Support\Str::plural('subject', $yearGroup['total_count']) }} · {{ $yearGroup['total_units'] }} units</span>
                            </div>

                            @foreach ($yearGroup['semesters'] as $semesterGroup)
                                <div class="tala-review-sem-block">
                                    <div class="tala-review-sem-block__head">
                                        <span>{{ $semesterGroup['label'] }}</span>
                                        <span class="tala-tabular">{{ $semesterGroup['total_count'] }} {{ \Illuminate\Support\Str::plural('subject', $semesterGroup['total_count']) }} · {{ $semesterGroup['total_units'] }} units</span>
                                    </div>
                                    <ul class="tala-semester-list" role="list">
                                        @foreach ($semesterGroup['rows'] as $row)
                                            <li wire:key="review-entry-{{ $row['id'] }}">
                                                <button
                                                    type="button"
                                                    wire:click="selectCurriculumSubject({{ $row['id'] }})"
                                                    @class([
                                                        'tala-semester-row',
                                                        'is-incomplete' => $row['incomplete_message'] !== null,
                                                        'is-moved' => ($row['moved_from_label'] ?? null) !== null,
                                                    ])
                                                >
                                                    <span class="tala-semester-row__lead">
                                                        <span class="tala-semester-row__main">
                                                            <span class="tala-semester-row__primary">
                                                                <span class="tala-semester-row__code">{{ $row['code'] }}</span>
                                                                <span class="tala-semester-row__title">{{ $row['title'] }}</span>
                                                                @if ($row['is_elective'])
                                                                    <x-filament::badge size="sm" color="gray">{{ $row['elective_label'] }}</x-filament::badge>
                                                                @endif
                                                                @if ($row['is_externally_arranged'])
                                                                    <x-filament::badge size="sm" color="warning">Externally arranged — no class schedule</x-filament::badge>
                                                                @elseif ($row['room_summary'])
                                                                    <x-filament::badge size="sm" color="gray">{{ $row['room_summary'] }}</x-filament::badge>
                                                                @endif
                                                                @if ($row['diff_status'])
                                                                    @php
                                                                        $diffColor = match (strtolower((string) $row['diff_status'])) {
                                                                            'added' => 'success',
                                                                            'moved' => 'info',
                                                                            'edited' => 'warning',
                                                                            'removed' => 'danger',
                                                                            default => 'gray',
                                                                        };
                                                                    @endphp
                                                                    <x-filament::badge size="sm" :color="$diffColor">
                                                                        {{ ($row['moved_from_label'] ?? null) !== null ? $row['moved_from_label'] : $row['diff_status'] }}
                                                                    </x-filament::badge>
                                                                @elseif (($row['moved_from_label'] ?? null) !== null)
                                                                    <x-filament::badge size="sm" color="info">{{ $row['moved_from_label'] }}</x-filament::badge>
                                                                @endif
                                                            </span>
                                                            @if ($row['incomplete_message'] !== null)
                                                                <span class="tala-semester-row__meta">
                                                                    <span class="tala-semester-row__warning">
                                                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" aria-hidden="true" />
                                                                        {{ $row['incomplete_message'] }}
                                                                    </span>
                                                                </span>
                                                            @endif
                                                        </span>
                                                    </span>
                                                    <span class="tala-semester-row__prereq-col">
                                                        @if ($row['prerequisites'] !== '')
                                                            <span class="tala-semester-row__prereq">{{ $row['prerequisites'] }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="tala-semester-row__trailing">
                                                        <span class="tala-semester-row__units">
                                                            {{ $row['units'] }}<span class="tala-visually-hidden"> units</span>
                                                        </span>
                                                        <span class="tala-semester-row__action" aria-hidden="true">
                                                            <span class="tala-row-edit-hint">{{ $canEdit ? 'Edit' : 'View' }}</span>
                                                            <x-filament::icon icon="heroicon-m-chevron-right" class="tala-semester-row__chevron" aria-hidden="true" />
                                                        </span>
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @else
                <div class="tala-semester-empty">
                    <x-servitech-empty-state
                        :illustrated="false"
                        icon="heroicon-o-book-open"
                        heading="No subjects in this curriculum yet"
                        description="Add subjects in the year steps before publishing."
                    />
                </div>
            @endif
        </x-filament::section>

        {{-- Compact Removed-from-Source List --}}
        @if ($diff['has_source'] && $diff['removed'] !== [])
            <x-filament::section
                :compact="true"
                heading="Removed from source"
                :description="'Subjects present in '.$diff['source_label'].' that were removed in this draft.'"
                class="tala-semester-card"
            >
                <ul class="tala-removed-list" role="list">
                    @foreach ($diff['removed'] as $removedRow)
                        <li class="tala-removed-list__item" wire:key="removed-source-{{ $removedRow['entry_id'] }}">
                            <div class="tala-removed-list__lead">
                                <x-filament::badge size="sm" color="danger">Removed</x-filament::badge>
                                <span class="tala-semester-row__code">{{ $removedRow['code'] }}</span>
                                <span class="tala-semester-row__title">{{ $removedRow['title'] }}</span>
                            </div>
                            <span class="tala-removed-list__meta tala-tabular">
                                Year {{ $removedRow['year_level'] }} · {{ $removedRow['term_label'] }} · {{ rtrim(rtrim(number_format((float) $removedRow['credit_units'], 2, '.', ''), '0'), '.') }} u
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        {{-- Optional TESDA & External Certifications (ONLY on Review step for drafts, or when present) --}}
        @if ($canEdit || ($externalQualifications ?? []) !== [])
            <x-filament::section
                :compact="true"
                heading="TESDA & External Certifications"
                description="Optional industry certificates assessed outside class subjects, such as TESDA NC II or NC III."
                class="tala-semester-card tala-certifications-card"
            >
                @if ($canEdit)
                    <x-slot:afterHeader>
                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            icon="heroicon-m-plus"
                            wire:click="mountAction('addExternalCompetency')"
                        >
                            Add external TESDA certification
                        </x-filament::button>
                    </x-slot:afterHeader>
                @endif

                <div class="tala-certifications-card__body">
                    @if (($externalQualifications ?? []) !== [])
                        @include('filament.resources.curriculum-versions.partials.external-qualifications', [
                            'qualifications' => $externalQualifications,
                            'canEdit' => $canEdit,
                        ])
                    @else
                        <x-servitech-empty-state
                            :illustrated="false"
                            icon="heroicon-o-academic-cap"
                            heading="No external certifications attached"
                            description="Most programs leave this empty unless a TESDA National Certificate is tracked or required."
                        />
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</section>
