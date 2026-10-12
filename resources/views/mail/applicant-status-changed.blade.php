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
Open your application
</x-mail::button>

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
