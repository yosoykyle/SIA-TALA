<x-mail::message>
# {{ $heading }}

@foreach ($safeLines as $line)
{{ $line }}

@endforeach

<x-mail::button :url="$actionUrl">
{{ $actionLabel }}
</x-mail::button>

The official applicant workspace remains the authoritative source if this email is delayed or unavailable.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
