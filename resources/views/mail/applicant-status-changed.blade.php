<x-mail::message>
# Application status: {{ $statusLabel }}

Hello {{ $recipientName }},

{{ $guidance }}

<x-mail::button :url="$actionUrl">
Open TALA Applicant Workspace
</x-mail::button>

Application reference: #{{ $applicantIntakeId }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
