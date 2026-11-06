<x-record-history heading="Submission history" :paginator="$submissionHistory" method="gotoSubmissionHistoryPage" :has-entries="$submissionHistory->isNotEmpty()">
    @foreach ($submissionHistory as $historical)
        <x-record-history-item :heading="'Version '.$historical->version_number.' · '.str($historical->state)->lower()->headline()" :date="$historical->invalidated_at ?? $historical->released_at ?? $historical->reviewed_at ?? $historical->submitted_at">
            @if ($historical->submitted_at)
                <p><strong>Submitted</strong> {{ $historical->submitted_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $historical->submitter?->getFilamentName() ?? 'Recorder not available' }}</p>
            @endif
            @php($returnedAt = $historical->returnedRows->isNotEmpty() || $historical->state === \App\Models\GradeRosterVersion::StateReturned ? ($historical->reviewed_at ?? $historical->returnedRows->first()?->returned_at) : null)
            @if ($returnedAt)
                <p><strong>Returned for changes</strong> {{ $returnedAt->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $historical->reviewer?->getFilamentName() ?? 'Recorder not available' }}</p>
            @endif
            @foreach ($historical->returnedRows->pluck('reason')->filter()->unique() as $returnReason)
                <p>{{ $returnReason }}</p>
            @endforeach
            @if ($historical->released_at)
                <p><strong>Released</strong> {{ $historical->released_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }} · {{ $historical->releaser?->getFilamentName() ?? 'Recorder not available' }}</p>
            @endif
            @if ($historical->invalidated_at)
                <p><strong>Submission no longer current</strong> {{ $historical->invalidated_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</p>
            @endif
            @if ($historical->invalidation_reason)<p>{{ $historical->invalidation_reason }}</p>@endif
        </x-record-history-item>
    @endforeach
    <x-slot:empty>No submitted version yet.</x-slot:empty>
</x-record-history>
