<x-mail::message>
# {{ $changeLabel }}

Hello {{ $recipientName }},

Current details and the safe next action are available in your authenticated Student Academics page.

For privacy, this email does not include grade values or attachments.

<x-mail::button :url="$actionUrl">
Open Student Academics
</x-mail::button>

If you did not expect this update, use the official support path published in the Student workspace.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
