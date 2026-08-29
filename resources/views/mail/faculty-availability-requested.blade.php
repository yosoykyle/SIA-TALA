<x-mail::message>
# Teaching availability action required

Hello {{ $recipientName }},

Please declare your hard unavailability, or confirm that you have no additional restrictions, for **{{ $termLabel }}** by **{{ $dueAt }}**.

<x-mail::button :url="$availabilityUrl">
Open My Availability
</x-mail::button>

This request does not assign a course, room, or official meeting. TALA uses your attributable declaration as one input to timetable readiness.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
