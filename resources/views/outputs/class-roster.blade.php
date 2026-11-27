@php
    $roster->loadMissing('termOffering.term.academicYear');
    $termHeading = collect([
        $roster->termOffering?->term?->academicYear?->label ? 'AY '.$roster->termOffering->term->academicYear->label : null,
        $roster->termOffering?->term?->label,
    ])->filter()->implode(' · ');
@endphp
<x-official-output-layout
    title="Current Class Roster"
    page-orientation="portrait"
    page-margin="12mm"
    :context="'Operational class roster · '.$roster->section?->code"
    :back-url="auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar) ? \App\Filament\Resources\GradeRosters\GradeRosterResource::getUrl('view', ['record' => $roster]) : \App\Filament\Pages\FacultyGradeRoster::getUrl(['roster' => $roster->id])"
    back-label="Back to class roster"
    document-class="class-roster-document"
>
    <x-slot:documentStyles>
        <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
        <style>
            .class-roster-document { font-family: var(--tala-font-body, 'Inter Variable', Inter, system-ui, sans-serif); font-size: 14px; }
            .class-roster-document .official-output-toolbar button { font: inherit; font-weight: 600; }
            .class-roster-document .official-output { box-sizing: border-box; max-width: 820px; padding: 36px; }
            .class-roster-document .official-output-toolbar { box-sizing: border-box; max-width: 820px; display: flex; justify-content: space-between; gap: 12px; }
            .class-roster-document .official-output-toolbar a { margin: 0; }
            .class-roster-brand { display: flex; justify-content: space-between; align-items: center; gap: 24px; margin-bottom: 24px; }
            .class-roster-brand p { margin: 0; color: #3f3f46; }
            .class-roster-brand img { width: 48px; height: 48px; object-fit: contain; }
            .class-roster-school { display: flex; align-items: center; gap: 14px; font-size: 12px; }
            .class-roster-address { display: block; color: #52525b; font-weight: 400; margin-top: 2px; }
            .class-roster-heading h1 { font-size: 20px; font-weight: 400; margin: 0 0 10px; }
            .class-roster-heading h2 { font-size: 26px; line-height: 1.2; margin: 0 0 10px; text-wrap: balance; }
            .class-roster-heading p { margin: 4px 0 0; line-height: 1.5; }
            .class-roster-context { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px 24px; padding: 14px 0; margin: 22px 0 20px; border-block: 1px solid #a1a1aa; font-size: 12px; }
            .class-roster-context p { margin: 0; line-height: 1.5; }
            .class-roster-document table { table-layout: fixed; }
            .class-roster-document th, .class-roster-document td { border: 0; border-bottom: 1px solid #d4d4d8; padding: 10px 12px; overflow-wrap: anywhere; text-align: start; }
            .class-roster-document th { font-size: 12px; }
            .class-roster-document td:first-child { font-variant-numeric: tabular-nums; }
            .class-roster-document tbody th[scope="row"] { font-weight: 700; background: transparent; }
            .class-roster-signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 36px; margin-top: 32px; break-inside: avoid; page-break-inside: avoid; }
            .class-roster-sign-line { border-top: 1px solid #3f3f46; padding-top: 6px; margin-top: 28px; font-size: 12px; }
            .class-roster-sign-line strong { display: block; color: #18181b; }
            .class-roster-sign-line span { display: block; color: #52525b; font-size: 11px; margin-top: 2px; }
            .class-roster-document .official-output-footer { margin-top: 28px; font-size: 11px; line-height: 1.6; }
            @media screen and (max-width: 600px) {
                .class-roster-document .official-output { padding: 24px 20px; }
                .class-roster-document .official-output-toolbar { padding: 16px 16px 0; }
                .class-roster-brand { align-items: start; gap: 16px; }
                .class-roster-school { align-items: start; gap: 10px; font-size: 10px; }
                .class-roster-brand img { width: 36px; height: 36px; }
                .class-roster-brand > p { max-width: 90px; font-size: 11px; text-align: end; }
                .class-roster-heading h2 { font-size: 22px; }
                .class-roster-context { display: grid; }
                .class-roster-signatures { grid-template-columns: 1fr; gap: 20px; }
                .class-roster-document th, .class-roster-document td { padding: 10px 8px; font-size: 12px; }
            }
            @media print {
                .class-roster-document .official-output { padding: 0; max-width: none; }
                .class-roster-document .class-roster-context { break-inside: avoid; }
                .class-roster-document th, .class-roster-document td { border: 0 !important; border-bottom: 1px solid #71717a !important; }
                .class-roster-document thead { display: table-header-group; }
            }
        </style>
    </x-slot:documentStyles>
    <x-slot:documentHeader>
        <header>
            <div class="class-roster-brand">
                <div class="class-roster-school">
                    <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="">
                    <div>
                        <strong>{{ config('institution.name') }}</strong>
                        @if (filled(config('institution.address')))
                            <span class="class-roster-address">{{ config('institution.address') }}</span>
                        @endif
                    </div>
                </div>
                <p>Operational reference</p>
            </div>
            <div class="class-roster-heading">
                <h1>Class roster</h1>
                <h2>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title }}</h2>
                <p>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} · {{ $roster->section?->code }}</p>
                @if ($termHeading !== '')
                    <p>{{ $termHeading }}</p>
                @endif
            </div>
            <div class="class-roster-context">
                <p>Assigned faculty <strong>{{ $roster->faculty?->getFilamentName() ?? 'Not recorded' }}</strong></p>
                <p><strong>{{ $roster->rows->count() }}</strong> {{ str('enrolled student')->plural($roster->rows->count()) }}</p>
                <p>As of {{ $generatedAt->format('j M Y, g:i A') }}</p>
            </div>
        </header>
    </x-slot:documentHeader>

        <div class="official-output-table">
        <table>
            <caption class="tala-sr-only">Current class members for {{ $roster->section?->code }}</caption>
            <colgroup><col style="width: 28%"><col style="width: 44%"><col style="width: 28%"></colgroup>
            <thead>
                <tr><th scope="col">Student number</th><th scope="col">Legal name</th><th scope="col">Program</th></tr>
            </thead>
            <tbody>
                @foreach ($roster->rows as $row)
                    @php($enrollment = $row->courseEnrollment?->enrollment)
                    @php($student = $enrollment?->studentProfile)
                    <tr>
                        <td>{{ $student?->student_number }}</td>
                        <th scope="row">{{ collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', ') }}</th>
                        <td>{{ $student?->program?->code ?? 'Not recorded' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <section class="class-roster-signatures" aria-label="Roster verification">
            <div class="class-roster-sign-line">
                <strong>{{ $roster->faculty?->getFilamentName() ?? 'Assigned Faculty' }}</strong>
                <span>Verified by · Assigned Faculty</span>
            </div>
            <div class="class-roster-sign-line">
                <strong>Office of the Registrar</strong>
                <span>Checked and recorded · Date</span>
            </div>
        </section>
    <x-slot:documentFooter>
        <footer class="official-output-footer">Current official class membership. Restricted to authorized school use.<br>Check discrepancies with the Registrar.</footer>
    </x-slot:documentFooter>
</x-official-output-layout>
