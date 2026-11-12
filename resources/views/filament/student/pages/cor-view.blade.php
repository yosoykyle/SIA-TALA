<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $available = $output['available'] ?? false;
        $subjects = collect($output['subjects'] ?? [])->groupBy(fn (array $subject): string => $subject['subject_code'].'|'.$subject['section']);
        $issuedAt = data_get($output, 'summary.issued_at') ? \Carbon\CarbonImmutable::parse(data_get($output, 'summary.issued_at'))->timezone(config('app.display_timezone')) : null;
        $selected = collect($versions)->firstWhere('id', $selectedVersionId);
        $documentStatus = data_get($output, 'summary.document_status') ?? ($selected && ! $selected['current'] ? 'Earlier copy' : 'Current');
    @endphp
    <div class="learner-enrollment student-cor-record">
        @if (! $available)
            <section class="student-record-empty tala-task-surface">
                <x-servitech-empty-state :illustrated="$illustrateEmpty" heading="No issued COR to show" :description="$output['reason'] ?? data_get($output, 'state.notice', 'Your COR appears after the Registrar finalizes official enrollment.')">
                    <x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button>
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
                    <dl class="student-cor-summary-facts tala-context-inset">
                        <div><dt>Subjects</dt><dd>{{ $subjects->count() }}</dd></div>
                        <div><dt>Total units</dt><dd>{{ (float) data_get($output, 'summary.total_units') }}</dd></div>
                        <div><dt>Issued</dt><dd>{{ $issuedAt?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                    </dl>
                </x-slot:details>
            </x-record-identity>
            <section class="tala-task-surface student-record-card student-cor-subjects" aria-labelledby="cor-subjects-heading">
                <div class="student-registration-subjects-heading"><h2 class="student-record-heading" id="cor-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Subjects</h2><p>Recorded on this issued copy. Later changes are shown on a new copy.</p></div>
                <div class="student-academic-table-wrap" role="region" aria-label="Subjects on this certificate" tabindex="0">
                    <table class="student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Class</th><th scope="col">Units</th><th scope="col">Schedule</th></tr></thead><tbody>
                        @foreach ($subjects as $meetings)
                            @php($subject = $meetings->first())
                            <tr wire:key="cor-subject-{{ $loop->index }}">
                                <th scope="row">{{ $subject['subject_description'] }}<span class="student-academic-secondary">{{ $subject['subject_code'] }}</span></th>
                                <td>{{ $subject['section'] }}</td>
                                <td class="student-academic-number">{{ (float) $subject['units'] }}</td>
                                <td class="cor-subject-meetings">@foreach ($meetings as $meeting)<p>{{ collect([$meeting['day'], $meeting['time'], $meeting['room'], $meeting['instructor']])->reject(fn ($part): bool => in_array($part, ['Not applicable', 'Not recorded', null, ''], true))->unique()->implode(' · ') }}</p>@endforeach</td>
                            </tr>
                        @endforeach
                    </tbody></table>
                </div>
            </section>
            @if (count($versions) > 1)
                <section class="tala-task-surface student-record-card student-cor-side" aria-labelledby="cor-versions-heading">
                    <h2 class="student-record-heading" id="cor-versions-heading"><x-filament::icon icon="heroicon-o-archive-box" />Earlier copies and other terms</h2>
                    <nav class="cor-versions" aria-label="Issued registration copies">
                        @foreach ($versions as $choice)
                            <a href="{{ $choice['url'] }}" wire:navigate wire:key="cor-version-{{ $choice['id'] }}" @if ($choice['id'] === $selectedVersionId) aria-current="page" @endif>{{ $choice['label'] }}</a>
                        @endforeach
                    </nav>
                </section>
            @endif
            <section class="tala-task-surface student-record-card student-cor-side" aria-labelledby="cor-fees-heading">
                <div class="student-registration-subjects-heading"><h2 class="student-record-heading" id="cor-fees-heading"><x-filament::icon icon="heroicon-o-banknotes" />Fees at issue</h2><p>Issued by {{ data_get($output, 'summary.issued_by', 'Not recorded') }}.</p></div>
                <dl class="learner-money">
                    @foreach (($output['fees'] ?? []) as $fee)<div wire:key="cor-fee-{{ $loop->index }}"><dt>{{ $fee['label'] }}</dt><dd>PHP {{ number_format((float) $fee['amount'], 2) }}</dd></div>@endforeach
                    <div class="student-money-total"><dt>Assessed total</dt><dd>{{ data_get($output, 'summary.assessment_total') === null ? 'Unavailable' : 'PHP '.number_format((float) data_get($output, 'summary.assessment_total'), 2) }}</dd></div>
                </dl>
                <div class="student-tile-actions"><x-filament::button tag="a" color="gray" icon="heroicon-o-banknotes" :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::button></div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
