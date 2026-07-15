<x-mail::message>
# Payment Posted

Hello {{ $recipientName }},

Your payment of {{ $amount }} for {{ $termLabel }} has been verified and posted to your T.A.L.A. student ledger.

This message confirms the ledger posting. It is not an official receipt or OR.

<x-mail::button :url="$financeUrl">
View Finance
</x-mail::button>

Sign in to review your current balance, payment acknowledgement, and OR-mapping status.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
