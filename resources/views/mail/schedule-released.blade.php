<x-mail::message>
# Your teaching schedule is ready

Hello {{ $recipientName }},

The Registrar published the official timetable for **{{ $termLabel }}**.

<x-mail::button :url="$scheduleUrl">
Open My Schedule
</x-mail::button>

Review your assigned days, times, rooms, and delivery modes before classes begin. If an assignment appears incorrect, contact the Registrar Office and refer to **My Schedule** in your Staff Workspace.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
