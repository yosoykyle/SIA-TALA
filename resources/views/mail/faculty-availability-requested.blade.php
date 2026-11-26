<x-mail::message>
# Submit your teaching availability

Hello {{ $recipientName }},

Please share when you can teach for **{{ $termLabel }}** by **{{ $dueAt }}**.

Open **My Availability** and choose how you can teach this term:

1. **Specific weekly times** — check the days you can teach and set your available weekly time ranges.
2. **Any school teaching time** — available across all approved school teaching hours.
3. **Unavailable this term** — you will not be assigned classes for this term.

Select **Save availability** to submit your response. If you already submitted availability, edit it directly on the page, enter a brief reason for the change, and select **Save update**.

<x-mail::button :url="$availabilityUrl">
Open My Availability
</x-mail::button>

Your response helps the Registrar plan teaching assignments. This request does not assign a subject, room, or official class meeting.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
