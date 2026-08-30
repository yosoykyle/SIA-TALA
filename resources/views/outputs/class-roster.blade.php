<x-official-output-layout
    title="Current Class Roster"
    subtitle="Operational reference — not an official issuance"
    page-orientation="landscape"
    page-margin="12mm"
    :context="$roster->section?->code"
    :generated-at="$generatedAt->format('F j, Y g:i A').' Asia/Manila'"
    :logo-src="asset('talalogo.png')"
>
    <div class="finance-grid">
        <p><strong>Exact Term</strong><br>{{ $roster->termOffering?->term?->label }}</p>
        <p><strong>Class reference</strong><br>{{ $roster->section?->code }}</p>
        <p><strong>Course</strong><br>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} — {{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title }}</p>
        <p><strong>Program or cohort</strong><br>{{ $roster->rows->first()?->courseEnrollment?->enrollment?->studentProfile?->program?->code ?? 'Not recorded' }}</p>
        <p><strong>Assigned Faculty</strong><br>{{ $roster->faculty?->getFilamentName() ?? 'Not recorded' }}</p>
        <p><strong>Current learner count</strong><br>{{ $roster->rows->count() }}</p>
    </div>

    <p class="official-output-notice">
        Current official membership as of {{ $generatedAt->format('F j, Y g:i A') }} Asia/Manila.
        This operational reference contains no grades, contact details, Applicant data, or financial data.
    </p>

    <div class="official-output-table">
        <table>
            <thead>
                <tr><th colspan="4">{{ $roster->section?->code }} · {{ $roster->termOffering?->term?->label }} · Current official membership</th></tr>
                <tr><th scope="col">Student number</th><th scope="col">Legal name</th><th scope="col">Program or cohort</th><th scope="col">Official enrollment state</th></tr>
            </thead>
            <tbody>
                @foreach ($roster->rows as $row)
                    @php($enrollment = $row->courseEnrollment?->enrollment)
                    @php($student = $enrollment?->studentProfile)
                    <tr>
                        <td>{{ $student?->student_number }}</td>
                        <td>{{ collect([$student?->last_name, $student?->first_name, $student?->middle_name])->filter()->implode(', ') }}</td>
                        <td>{{ $student?->program?->code ?? 'Not recorded' }}</td>
                        <td>{{ str($enrollment?->canonical_outcome ?? $enrollment?->status)->headline() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-official-output-layout>
