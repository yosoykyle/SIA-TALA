<x-mail::message>
# Application status: {{ $statusLabel }}

Hello {{ $recipientName }},

{{ $guidance }}

Program: {{ $programLabel }}

Term: {{ $termLabel }}

Responsible office: {{ $responsibleOffice }}

Application reference: #{{ $applicantIntakeId }}

## Next action

{{ $nextAction }}

<x-mail::button :url="$actionUrl">
Open TALA Applicant Workspace
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
