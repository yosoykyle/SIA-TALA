<x-mail::message>
# Check your application status

You requested a private link to check your Servitech application.

<x-mail::button :url="$statusUrl">View application status</x-mail::button>

This link expires 15 minutes after your request. It shows only status and next steps. Keep it private.

If you did not request this email, you can ignore it. Your application has not changed.

You can also [sign in]({{ route('filament.admin.auth.login') }}) to continue your application.
</x-mail::message>
