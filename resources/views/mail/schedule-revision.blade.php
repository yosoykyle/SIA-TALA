<x-mail::message>
@if ($isCancellation)
# Class cancelled
@else
# Your class schedule was updated
@endif

Hello {{ $recipientName }},

@if ($isCancellation)
The Registrar Office cancelled one or more classes in your published schedule{{ $termLabel !== '' ? ' for **'.$termLabel.'**' : '' }}.
@else
The Registrar Office updated one or more meetings in your published schedule{{ $termLabel !== '' ? ' for **'.$termLabel.'**' : '' }}.
@endif

@if ($termLabel !== '' || $effectiveDate !== null || $reason !== null)
<x-mail::panel>
@if ($termLabel !== '')
**Term:** {{ $termLabel }}  
@endif
@if ($effectiveDate !== null)
**Effective date:** {{ $effectiveDate }}  
@endif
@if ($reason !== null)
**Reason:** {{ $reason }}
@endif
</x-mail::panel>
@endif

@foreach ($scheduleChanges as $change)
## {{ $change['course'] }} — {{ $change['section'] }}

@if (! empty($change['is_cancelled']))
**Status: Cancelled** ({{ $change['change_label'] }})
@else
**{{ $change['change_label'] }}**@if (($change['meeting_sequence'] ?? 0) > 0) (meeting {{ $change['meeting_sequence'] }})@endif
@endif

| Detail | Previous | Current |
| :--- | :--- | :--- |
@if (! empty($change['is_cancelled']))
| Status | {{ $change['before']['state'] }} | **Cancelled** |
@endif
| Faculty | {{ $change['before']['faculty'] }} | {{ ! empty($change['is_cancelled']) ? 'Cancelled' : $change['after']['faculty'] }} |
| Room | {{ $change['before']['room'] }} | {{ ! empty($change['is_cancelled']) ? 'Cancelled' : $change['after']['room'] }} |
| Day | {{ $change['before']['day'] }} | {{ ! empty($change['is_cancelled']) ? 'Cancelled' : $change['after']['day'] }} |
| Time | {{ $change['before']['time_range'] }} | {{ ! empty($change['is_cancelled']) ? 'Cancelled' : $change['after']['time_range'] }} |
| Delivery mode | {{ $change['before']['modality'] }} | {{ ! empty($change['is_cancelled']) ? 'Cancelled' : $change['after']['modality'] }} |
@endforeach

@if ($recipientRole === 'faculty')
<x-mail::button :url="$scheduleUrl">
Open My Schedule
</x-mail::button>

Sign in to your **Staff Workspace** and open **My Schedule** before your next class. If an assignment appears incorrect, contact the Registrar Office.
@else
<x-mail::button :url="$scheduleUrl">
Open Class Schedule
</x-mail::button>

@if ($affectedEnrollments !== [])
Your current Certificate of Registration (COR) remains available under **Enrollment**. If this change requires a Registrar placement decision, your Student Hub will show the next step rather than changing your enrollment silently.
@endif

Sign in to your **Student Hub** and open **Class schedule** before attending your next class. If an assignment appears incorrect, contact the Registrar Office.
@endif

Regards,<br>
{{ config('institution.name') }} via {{ config('app.name') }}
</x-mail::message>
