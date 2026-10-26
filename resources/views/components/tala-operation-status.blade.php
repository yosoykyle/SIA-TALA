@props(['run'])

@php($queued = $run->status === \App\Models\ScheduleGenerationRun::StatusQueued)
<div {{ $attributes->class(['tala-operation-status']) }} role="status" aria-live="polite" aria-atomic="true">
    <x-filament::loading-indicator aria-hidden="true" />
    <div>
        <h2>{{ $queued ? 'Waiting for the timetable worker' : 'Scheduling request in progress' }}</h2>
        <p>{{ $queued ? 'Your whole-Term request is saved and waiting to be picked up.' : 'The worker has started sending this request to the scheduling service. Waiting for its result and validation.' }}</p>
        <p class="tala-work-footnote">Attempt #{{ $run->id }} · This page refreshes automatically. You can leave and return; any existing official timetable stays in use.</p>
    </div>
</div>
