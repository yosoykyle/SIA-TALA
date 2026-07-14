<x-mail::message>
# Schedule Released

Hello {{ $recipientName }},

Your class schedule for {{ $termLabel }} is now available in T.A.L.A.

<x-mail::button :url="$scheduleUrl">
View Schedule
</x-mail::button>

Sign in to view the current published schedule.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
