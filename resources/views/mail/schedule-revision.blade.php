<x-mail::message>
# Published schedule updated

Hello {{ $recipientName }},

Your published class schedule has changed. The current assignment details are listed below.

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

Sign in to TALA to view the current published schedule.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
