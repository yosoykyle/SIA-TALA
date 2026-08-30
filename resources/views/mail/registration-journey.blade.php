<x-mail::message>
# {{ $heading }}

Hello {{ $recipientName }},

{{ $messageLine }}

<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

This message reports an attributable TALA record. It does not change registration, placement, finance, or enrollment by itself.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
