<x-mail::message>
# Your class schedule is ready

Hello {{ $recipientName }},

The Registrar Office has released your current published schedule for **{{ $termLabel }}**.

<x-mail::button :url="$scheduleUrl">
View Schedule
</x-mail::button>

Review the day, time, room, modality, and faculty for every class before attending. If an assignment appears incorrect, contact the Registrar Office and refer to the schedule shown in your Student Hub.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
