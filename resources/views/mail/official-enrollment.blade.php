<x-mail::message>
# Your official enrollment is confirmed

Hello {{ $recipientName }},

The Registrar finalized your enrollment for **{{ $termLabel }}**. Your Student workspace is active on the same account, and your current class schedule and immutable Certificate of Registration are available in TALA.

<x-mail::button :url="$corUrl">
View Current COR
</x-mail::button>

If the link is unavailable, sign in to the Student Hub and open Enrollment or Current COR. This message does not change the authoritative enrollment record.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
