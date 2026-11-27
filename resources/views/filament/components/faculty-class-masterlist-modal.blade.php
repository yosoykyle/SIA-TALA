<div class="faculty-class-masterlist-modal space-y-4">
    @if (! $isCurrent)
        <section class="tala-access-alert tala-access-alert--warning" role="status">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="tala-access-alert__icon" aria-hidden="true" />
            <div class="tala-access-alert__content">
                <h3 class="tala-access-alert__title">Class list needs update</h3>
                <div class="tala-access-alert__body">
                    <p>Ask the Registrar to refresh this class list, then reload to view, print, or export the official student masterlist.</p>
                </div>
            </div>
        </section>
    @elseif ($count === 0)
        <x-servitech-empty-state
            heading="No students enrolled yet"
            heading-tag="h3"
            description="Officially enrolled students appear here after the Registrar finalizes their enrollment."
            icon="heroicon-o-user-group"
        />
    @else
        <p class="accounting-export-note">
            <strong class="tala-tabular-input">{{ number_format($count) }}</strong>
            official {{ str('student')->plural($count) }} · Ordered by legal name · Current as of
            <time datetime="{{ $generatedAt->toIso8601String() }}">{{ \App\Support\DisplayDateTime::format($generatedAt, 'M j, Y · g:i A') }}</time>.
        </p>

        <x-data-table label="Official student masterlist" class="faculty-class-table-wrap" records>
            <thead>
                <tr role="row">
                    <th scope="col" role="columnheader">Student name</th>
                    <th scope="col" role="columnheader" class="tala-data-table-number">Student number</th>
                    <th scope="col" role="columnheader">Program</th>
                    <th scope="col" role="columnheader">Enrollment state</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($students as $student)
                    <tr wire:key="masterlist-student-{{ $roster->id }}-{{ $student['id'] }}" role="row">
                        <th scope="row" role="rowheader">{{ $student['legal_name'] }}</th>
                        <td role="cell" class="tala-data-table-number">
                            <span class="tala-data-table-mobile-label" aria-hidden="true">Student number</span>
                            {{ $student['student_number'] }}
                        </td>
                        <td role="cell">
                            <span class="tala-data-table-mobile-label" aria-hidden="true">Program</span>
                            {{ $student['program'] ?: '—' }}
                        </td>
                        <td role="cell">
                            <span class="tala-data-table-mobile-label" aria-hidden="true">Enrollment state</span>
                            <x-filament::badge color="success">{{ $student['enrollment_state'] }}</x-filament::badge>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-data-table>

        <p class="accounting-export-note">Restricted to authorized class and Registrar access. Current membership is verified again when printing or exporting CSV.</p>
    @endif
</div>
