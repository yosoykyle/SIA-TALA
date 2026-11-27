<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $available = $output['available'] ?? false;
        $subjects = collect($output['subjects'] ?? [])->groupBy(fn (array $subject): string => $subject['subject_code'].'|'.$subject['section']);
        $issuedAt = data_get($output, 'summary.issued_at') ? \Carbon\CarbonImmutable::parse(data_get($output, 'summary.issued_at'))->timezone(config('app.display_timezone')) : null;
        $selected = collect($versions)->firstWhere('id', $selectedVersionId);
        $documentStatus = data_get($output, 'summary.document_status') ?? ($selected && ! $selected['current'] ? 'Earlier copy' : 'Current');
        $showPreviousTermNote = $documentStatus === 'Previous term';
        $modeLabel = fn ($mode): string => match (strtoupper(str_replace([' ', '-'], '_', trim((string) $mode)))) {
            'FACE_TO_FACE' => 'Face-to-face',
            'ONLINE' => 'Online',
            'HYBRID' => 'Hybrid',
            default => (string) $mode,
        };
    @endphp
    <div class="student-cor-container">
        <x-tala-navigation label="Enrollment sections" stretch class="student-workspace-nav">
            <x-filament::tabs.item
                tag="a"
                wire:navigate
                :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')"
                :active="false"
                icon="heroicon-o-clipboard-document-check"
            >
                Current registration
            </x-filament::tabs.item>
            <x-filament::tabs.item
                tag="a"
                wire:navigate
                :href="\App\Filament\Student\Pages\CorView::getUrl(panel: 'student')"
                :active="true"
                icon="heroicon-o-document-check"
            >
                Certificate (COR)
            </x-filament::tabs.item>
        </x-tala-navigation>
        <div class="learner-enrollment student-cor-record">
            @if (! $available)
                <section class="student-record-empty tala-task-surface">
                    <x-servitech-empty-state illustrated heading="No issued COR to show" :description="$output['reason'] ?? data_get($output, 'state.notice', 'Your COR appears after the Registrar finalizes official enrollment.')">
                        <x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')" icon="heroicon-o-clipboard-document-check">View enrollment</x-filament::button>
                    </x-servitech-empty-state>
                </section>
        @else
            <x-record-identity
                class="student-cor-summary"
                :name="data_get($output, 'summary.student_name')"
                :number="data_get($output, 'summary.student_number')"
                :program="collect([data_get($output, 'summary.program'), data_get($output, 'summary.term')])->filter()->implode(' · ')"
                number-label="Student number"
                role="group"
                aria-label="Issued certificate summary"
            >
                <x-slot:status>
                    <x-filament::badge :color="$documentStatus === 'Current' ? 'success' : 'gray'" icon="heroicon-o-document-check">
                        {{ $documentStatus }}
                    </x-filament::badge>
                </x-slot:status>
                <x-slot:details>
                    @if ($showPreviousTermNote)
                        <p class="student-tile-meta" style="margin-block-end: .75rem;">
                            Last completed term · {{ data_get($output, 'summary.term', 'Term unavailable') }}. This is the latest issued COR from your last completed term.
                        </p>
                    @endif
                    <dl class="student-cor-summary-facts tala-context-inset">
                        <div><dt>Subjects</dt><dd>{{ $subjects->count() }}</dd></div>
                        <div><dt>Total units</dt><dd>{{ (float) data_get($output, 'summary.total_units') }}</dd></div>
                        <div><dt>Issued</dt><dd>{{ $issuedAt?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                    </dl>
                </x-slot:details>
            </x-record-identity>
            <section class="tala-task-surface student-card--two-tone student-cor-subjects" aria-labelledby="cor-subjects-heading">
                <div class="student-card__body">
                    <div class="student-registration-subjects-heading" style="margin-block-end: 0;">
                        <h2 class="student-record-heading" id="cor-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Subjects</h2>
                        <p>Recorded on this issued copy. Later changes are shown on a new copy.</p>
                    </div>
                </div>
                @if ($subjects->isNotEmpty())
                    <div class="student-card__table-flush student-academic-table-wrap" role="region" aria-label="Subjects on this certificate" tabindex="0">
                        <table class="tala-data-table student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Section / Modality</th><th scope="col">Units</th><th scope="col">Schedule</th></tr></thead><tbody>
                            @foreach ($subjects as $meetings)
                                @php
                                    $subject = $meetings->first();
                                    $modalities = $meetings->pluck('modality')
                                        ->reject(fn ($part): bool => in_array($part, ['Not applicable', 'Not recorded', null, ''], true))
                                        ->map($modeLabel)
                                        ->unique()
                                        ->implode('; ');
                                    $sectionLabel = filled($subject['section'] ?? null) && $subject['section'] !== 'Not recorded'
                                        ? 'Sec. '.$subject['section']
                                        : ($subject['section'] ?? 'Not recorded');
                                @endphp
                                <tr wire:key="cor-subject-{{ $loop->index }}">
                                    <th scope="row">{{ $subject['subject_description'] }}<span class="student-academic-secondary">{{ $subject['subject_code'] }}</span></th>
                                    <td>
                                        @if ($modalities !== '')
                                            <span>{{ $modalities }}</span>
                                            <span class="student-academic-secondary">{{ $sectionLabel }}</span>
                                        @else
                                            {{ $sectionLabel }}
                                        @endif
                                    </td>
                                    <td class="student-academic-number">{{ (float) $subject['units'] }}</td>
                                    <td class="cor-subject-meetings">@foreach ($meetings as $meeting)<p>{{ collect([$meeting['day'], $meeting['time'], $meeting['room'], $meeting['instructor']])->reject(fn ($part): bool => in_array($part, ['Not applicable', 'Not recorded', null, ''], true))->unique()->implode(' · ') }}</p>@endforeach</td>
                                </tr>
                            @endforeach
                        </tbody></table>
                    </div>
                @else
                    <div class="student-card__body" style="border-block-start: 1px solid var(--tala-edge);">
                        <x-servitech-empty-state
                            illustrated
                            heading="No subjects recorded on this copy"
                            description="No subjects are recorded on this issued certificate copy."
                            heading-tag="h3"
                        />
                    </div>
                @endif
                @if (data_get($output, 'summary.enrollment_id'))
                    <div class="student-card__footer">
                        <p class="student-tile-meta" style="margin: 0;">{{ $subjects->count() }} {{ $subjects->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) data_get($output, 'summary.total_units') }} units</p>
                        <x-filament::button
                            tag="a"
                            color="gray"
                            size="sm"
                            icon="heroicon-o-printer"
                            target="_blank"
                            rel="noopener noreferrer"
                            :href="route('cor.print', array_filter([
                                'enrollment' => data_get($output, 'summary.enrollment_id'),
                                'version' => data_get($output, 'summary.cor_version'),
                            ]))"
                        >
                            Print or save PDF
                        </x-filament::button>
                    </div>
                @endif
            </section>
            <div class="student-cor-sidebar">
                @if (count($versions) > 1)
                    <section class="tala-task-surface student-card--two-tone student-cor-side" aria-labelledby="cor-versions-heading">
                        <div class="student-card__body">
                            <h2 class="student-record-heading" id="cor-versions-heading"><x-filament::icon icon="heroicon-o-archive-box" />Earlier copies and other terms</h2>
                            <nav class="student-cor-versions-list" aria-label="Issued registration copies">
                                @foreach ($versions as $choice)
                                        @php
                                            $parts = array_map('trim', explode('·', (string) ($choice['label'] ?? '')));
                                            $termTitle = $parts[0] ?? ($choice['label'] ?? 'Term unavailable');
                                            $copyStatus = $parts[1] ?? ($choice['current'] ? 'Current' : 'Earlier copy');
                                            $issuedMeta = $parts[2] ?? null;
                                            $compactIssued = $issuedMeta ? trim(preg_replace('/^issued\s+/i', '', $issuedMeta) ?? $issuedMeta) : null;
                                            $isSelected = $choice['id'] === $selectedVersionId;
                                        @endphp
                                        <a
                                            href="{{ $choice['url'] }}"
                                            wire:navigate
                                            wire:key="cor-version-{{ $choice['id'] }}"
                                            @class(['student-cor-version-row', 'is-current' => $isSelected])
                                            @if ($isSelected) aria-current="page" @endif
                                        >
                                            <span style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.375rem; min-inline-size: 0;">
                                                <span style="overflow-wrap: anywhere;">{{ $termTitle }}</span>
                                                <x-filament::badge size="sm" :color="$copyStatus === 'Current' ? 'success' : 'gray'">{{ $copyStatus }}</x-filament::badge>
                                            </span>
                                            @if ($compactIssued)
                                                <span title="{{ ucfirst($issuedMeta) }}" style="flex: none; font-size: 0.75rem; color: var(--tala-muted); font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $compactIssued }}</span>
                                            @endif
                                        </a>
                                @endforeach
                            </nav>
                        </div>
                    </section>
                @endif
                <section class="tala-task-surface student-card--two-tone student-cor-side" aria-labelledby="cor-fees-heading">
                    <div class="student-card__body">
                        <div class="student-registration-subjects-heading" style="margin-block-end: 0;">
                            <h2 class="student-record-heading" id="cor-fees-heading"><x-filament::icon icon="heroicon-o-banknotes" />Fees at issue</h2>
                        </div>
                        <dl class="learner-money">
                            @foreach (($output['fees'] ?? []) as $fee)<div wire:key="cor-fee-{{ $loop->index }}"><dt>{{ $fee['label'] }}</dt><dd>PHP {{ number_format((float) $fee['amount'], 2) }}</dd></div>@endforeach
                            <div class="student-money-total"><dt>Assessed total</dt><dd>{{ data_get($output, 'summary.assessment_total') === null ? 'Unavailable' : 'PHP '.number_format((float) data_get($output, 'summary.assessment_total'), 2) }}</dd></div>
                        </dl>
                    </div>
                    <div class="student-card__footer">
                        <p class="student-tile-meta" style="margin: 0;">Issued by {{ data_get($output, 'summary.issued_by', 'Not recorded') }}.</p>
                    </div>
                </section>
            </div>
        @endif
        </div>
    </div>
</x-filament-panels::page>
