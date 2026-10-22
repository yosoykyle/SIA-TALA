<x-official-output-layout
    title="Current Class Roster"
    page-orientation="landscape"
    page-margin="12mm"
    :context="'Operational class roster · '.$roster->section?->code"
    :back-url="auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar) ? \App\Filament\Resources\GradeRosters\GradeRosterResource::getUrl('view', ['record' => $roster]) : \App\Filament\Pages\FacultyGradeRoster::getUrl(['roster' => $roster->id])"
    back-label="Back to class roster"
    document-class="class-roster-document"
>
    <x-slot:documentStyles>
        <link rel="stylesheet" href="{{ asset('fonts/filament/filament/inter/index.css') }}">
        <style>
            .class-roster-document { font-family: 'Inter Variable', Arial, sans-serif; font-size: 14px; }
            .class-roster-document .official-output-toolbar button { font: inherit; font-weight: 600; }
            .class-roster-document .official-output { box-sizing: border-box; max-width: 1120px; padding: 40px; }
            .class-roster-document .official-output-toolbar { box-sizing: border-box; max-width: 1120px; display: flex; justify-content: space-between; gap: 12px; }
            .class-roster-document .official-output-toolbar a { margin: 0; }
            .class-roster-brand { display: flex; justify-content: space-between; align-items: center; gap: 24px; margin-bottom: 28px; }
            .class-roster-brand p { margin: 0; color: #374151; }
            .class-roster-brand img { width: 48px; height: 48px; object-fit: contain; }
            .class-roster-school { display: flex; align-items: center; gap: 14px; font-size: 12px; }
            .class-roster-heading h1 { font-size: 22px; font-weight: 400; margin: 0 0 14px; }
            .class-roster-heading h2 { font-size: 30px; line-height: 1.2; margin: 0 0 12px; text-wrap: balance; }
            .class-roster-heading p { margin: 6px 0 0; line-height: 1.5; }
            .class-roster-context { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px 24px; padding: 16px 0; margin: 28px 0 24px; border-block: 1px solid #9ca3af; font-size: 12px; }
            .class-roster-context p { margin: 0; line-height: 1.5; }
            .class-roster-document table { table-layout: fixed; }
            .class-roster-document th, .class-roster-document td { border: 0; border-bottom: 1px solid #d1d5db; padding: 12px 16px; overflow-wrap: anywhere; }
            .class-roster-document th { font-size: 12px; }
            .class-roster-document td:first-child { font-variant-numeric: tabular-nums; }
            .class-roster-document td:nth-child(2) { font-weight: 700; }
            .class-roster-document .official-output-footer { margin-top: 40px; font-size: 11px; line-height: 1.6; }
            @media screen and (max-width: 600px) {
                .class-roster-document .official-output { padding: 24px 20px; }
                .class-roster-document .official-output-toolbar { padding: 16px 16px 0; }
                .class-roster-brand { align-items: start; gap: 16px; }
                .class-roster-school { align-items: start; gap: 10px; font-size: 10px; }
                .class-roster-brand img { width: 36px; height: 36px; }
                .class-roster-brand > p { max-width: 90px; font-size: 11px; text-align: right; }
                .class-roster-heading h2 { font-size: 26px; }
                .class-roster-context { display: grid; }
                .class-roster-document th, .class-roster-document td { padding: 12px 8px; font-size: 12px; }
            }
            @media print {
                .class-roster-document .official-output { padding: 0; max-width: none; }
                .class-roster-document .class-roster-context { break-inside: avoid; }
                .class-roster-document th, .class-roster-document td { border: 0 !important; border-bottom: 1px solid #6b7280 !important; }
                .class-roster-document thead { display: table-header-group; }
            }
        </style>
    </x-slot:documentStyles>
    <x-slot:documentHeader>
        <header>
            <div class="class-roster-brand">
                <div class="class-roster-school"><img src="{{ asset('images/brand/servitech-crest.webp') }}" alt=""><strong>{{ config('institution.name') }}</strong></div>
                <p>Operational reference</p>
            </div>
            <div class="class-roster-heading">
                <h1>Class roster</h1>
                <h2>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title }}</h2>
                <p>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} · {{ $roster->section?->code }}</p>
                <p>{{ $roster->termOffering?->term?->label }}</p>
            </div>
            <div class="class-roster-context">
                <p>Assigned faculty <strong>{{ $roster->faculty?->getFilamentName() ?? 'Not recorded' }}</strong></p>
                <p><strong>{{ $roster->rows->count() }}</strong> {{ str('enrolled student')->plural($roster->rows->count()) }}</p>
                <p>As of {{ $generatedAt->format('j M Y, g:i A') }} · Asia/Manila</p>
            </div>
        </header>
    </x-slot:documentHeader>

        <table aria-label="Current class members">
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
                        <td>{{ collect([$student?->last_name, collect([$student?->first_name, $student?->middle_name])->filter()->implode(' ')])->filter()->implode(', ') }}</td>
                        <td>{{ $student?->program?->code ?? 'Not recorded' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    <x-slot:documentFooter>
        <footer class="official-output-footer">Current official class membership. Restricted to authorized school use.<br>Check discrepancies with the Registrar.</footer>
    </x-slot:documentFooter>
</x-official-output-layout>
