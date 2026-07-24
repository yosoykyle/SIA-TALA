<x-mail::message>
# Your payment was posted

Hello {{ $recipientName }},

The Accounting Office verified and posted **{{ $amount }}** for **{{ $termLabel }}** to your TALA student ledger.

This email confirms the ledger posting only. It is not an official receipt or official-receipt (OR) record.

<x-mail::button :url="$financeUrl">
Review Finance
</x-mail::button>

Sign in to review your current balance, payment acknowledgement, and OR status. If the amount or status appears incorrect, contact the Accounting Office before submitting another payment.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
