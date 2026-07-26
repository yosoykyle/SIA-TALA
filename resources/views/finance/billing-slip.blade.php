<x-official-output-layout
    title="Billing Slip"
    context="Internal Billing Reference"
    :subtitle="$slip['summary']['term']"
    :generated-at="\App\Support\DisplayDateTime::format($slip['generated_at'], 'M d, Y h:i A')"
>
    <p>
        Use this slip as a reference for the current assessment amount. It is not proof of payment or an official receipt.
    </p>

    <div class="official-output-table">
        <table>
            <tbody>
                <tr><th>Student Number</th><td>{{ $slip['summary']['student_number'] }}</td></tr>
                <tr><th>Student Name</th><td>{{ $slip['summary']['student_name'] }}</td></tr>
                <tr><th>Program</th><td>{{ $slip['summary']['program'] }}</td></tr>
                <tr><th>Term</th><td>{{ $slip['summary']['term'] }}</td></tr>
                <tr><th>Payment Stage</th><td>{{ $slip['state']['current_due_source'] }}</td></tr>
                <tr><th>Amount Currently Due</th><td>{{ $slip['state']['current_due'] }}</td></tr>
                <tr><th>Internal Assessment Reference</th><td>TALA-ASSESSMENT-{{ $slip['summary']['assessment_id'] }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="official-output-notice">{{ $slip['disclaimer'] }}</div>
</x-official-output-layout>
