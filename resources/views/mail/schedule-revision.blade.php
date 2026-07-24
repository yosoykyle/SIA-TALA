<x-mail::message>
# Your class schedule was updated

Hello {{ $recipientName }},

The Registrar Office changed one or more assignments in your current published schedule. The previous and current details are listed below.

@foreach ($scheduleChanges as $change)
## {{ $change['course'] }} - {{ $change['section'] }}

**{{ $change['change_label'] }}** (meeting {{ $change['meeting_sequence'] }})

| Detail | Previous | Current |
| :--- | :--- | :--- |
| Faculty | {{ $change['before']['faculty'] }} | {{ $change['after']['faculty'] }} |
| Room | {{ $change['before']['room'] }} | {{ $change['after']['room'] }} |
| Day | {{ $change['before']['day'] }} | {{ $change['after']['day'] }} |
| Time | {{ $change['before']['starts_at'] }}-{{ $change['before']['ends_at'] }} | {{ $change['after']['starts_at'] }}-{{ $change['after']['ends_at'] }} |
| Modality | {{ $change['before']['modality'] }} | {{ $change['after']['modality'] }} |
@endforeach

Sign in to TALA and open **Schedule** before attending your next class. The Student Hub shows the current published schedule. If an assignment appears incorrect, contact the Registrar Office.

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
