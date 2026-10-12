<x-mail::message>
# Submit your teaching availability

Hello {{ $recipientName }},

The Registrar requests your availability for **{{ $termLabel }}**. Please submit your response by **{{ $dueAt }}**.

Open My Availability and select **Submit my availability** to answer:

1. **Can you teach this Term?** Select Yes or No.
2. If Yes, **Any weekly times you cannot teach?** Select Yes and enter each unavailable Day, From and To in Asia/Manila, or select No to confirm no additional restrictions.

If you already submitted a response, select **Update my availability** to change it and give a reason.

<x-mail::button :url="$availabilityUrl">
Open My Availability
</x-mail::button>

Your response helps the Registrar plan teaching assignments. This request does not assign a course, room, or official meeting.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
