<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $available = $output['available'] ?? false;
        $subjects = collect($output['subjects'] ?? [])->groupBy(fn (array $subject): string => $subject['subject_code'].'|'.$subject['section']);
        $issuedAt = data_get($output, 'summary.issued_at') ? \Carbon\CarbonImmutable::parse(data_get($output, 'summary.issued_at'))->timezone(config('app.display_timezone')) : null;
        $selected = collect($versions)->firstWhere('id', $selectedVersionId);
        $documentStatus = data_get($output, 'summary.document_status') ?? ($selected && ! $selected['current'] ? 'Earlier copy' : 'Current');
    @endphp
    <div class="learner-enrollment space-y-4">
        @if (! $available)
            <x-filament::section heading="No issued COR to show" icon="heroicon-o-document-text">
                <div class="space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $output['reason'] ?? data_get($output, 'state.notice', 'Your COR appears after the Registrar finalizes official enrollment.') }}</p>
                    <x-filament::button tag="a" :href="\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student')">View enrollment</x-filament::button>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="cor-identity">
                    <x-filament::badge :color="$documentStatus === 'Current' ? 'success' : 'gray'">Issued copy · {{ $documentStatus }}</x-filament::badge>
                    <h2 class="text-xl font-semibold">{{ data_get($output, 'summary.student_name') }}</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ data_get($output, 'summary.student_number') }}</p>
                    <p class="font-medium">{{ data_get($output, 'summary.program') }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ data_get($output, 'summary.term') }}</p>
                </div>
                <dl class="cor-facts">
                    <div><dt>Subjects</dt><dd>{{ $subjects->count() }}</dd></div>
                    <div><dt>Total units</dt><dd>{{ data_get($output, 'summary.total_units') }}</dd></div>
                    <div><dt>Issued</dt><dd>{{ $issuedAt?->format('M j, Y') ?? 'Not recorded' }}</dd></div>
                </dl>
            </x-filament::section>
            <x-filament::section heading="Subjects" description="Recorded on this issued copy. Later changes are shown on a new copy.">
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($subjects as $meetings)
                        @php($subject = $meetings->first())
                        <div wire:key="cor-subject-{{ $loop->index }}" class="grid gap-2 py-3 text-sm md:grid-cols-2">
                            <div><strong>{{ $subject['subject_description'] }}</strong><p class="text-gray-600 dark:text-gray-300">{{ $subject['subject_code'] }} · {{ $subject['units'] }} units · {{ $subject['section'] }}</p></div>
                            <div class="cor-subject-meetings">
                                @foreach ($meetings as $meeting)<p>{{ $meeting['day'] }} · {{ $meeting['time'] }} · {{ $meeting['room'] }}@if ($meeting['instructor'] !== 'Not recorded') · {{ $meeting['instructor'] }}@endif</p>@endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
            @if (count($versions) > 1)
                <details class="learner-disclosure">
                    <summary>Earlier copies and other terms</summary>
                    <nav class="cor-versions" aria-label="Issued registration copies">
                        @foreach ($versions as $choice)
                            <a href="{{ $choice['url'] }}" wire:navigate wire:key="cor-version-{{ $choice['id'] }}" @if ($choice['id'] === $selectedVersionId) aria-current="page" @endif>{{ $choice['label'] }}</a>
                        @endforeach
                    </nav>
                </details>
            @endif
            <details class="learner-disclosure"><summary>Fees at issuance and issue details</summary><div class="space-y-3 text-sm">
                <dl class="learner-money">
                    <div><dt>Assessed total</dt><dd>{{ data_get($output, 'summary.assessment_total') === null ? 'Unavailable' : 'PHP '.number_format((float) data_get($output, 'summary.assessment_total'), 2) }}</dd></div>
                    @foreach (($output['fees'] ?? []) as $fee)<div wire:key="cor-fee-{{ $loop->index }}"><dt>{{ $fee['label'] }}</dt><dd>PHP {{ number_format((float) $fee['amount'], 2) }}</dd></div>@endforeach
                </dl>
                <p class="text-gray-600 dark:text-gray-300">Issued by {{ data_get($output, 'summary.issued_by', 'Not recorded') }}. Later payments and coverage appear in Finance.</p>
                <x-filament::link :href="\App\Filament\Student\Pages\Finance::getUrl(panel: 'student')">View fees and payments</x-filament::link>
            </div></details>
        @endif
    </div>
</x-filament-panels::page>
