<section class="accounting-history-group" aria-labelledby="accounting-reviewed-changes">
    <h3 id="accounting-reviewed-changes">Reviewed changes and corrections</h3>
    <div class="accounting-obligations">
        @forelse ($reviewHistory as $entry)
            @php
                $source = $entry->lifecycleReview?->lifecycleReviewSource;
                $isCurrent = $entry->lifecycleReview?->latestLifecycleReviewResult?->id === $entry->id;
                $outcome = \App\Actions\StudentLifecycle\LifecycleAccountingReview::outcomes(true)[$entry->payload['outcome']] ?? 'Review result unavailable';
            @endphp
            <div wire:key="student-change-review-result-{{ $entry->id }}">
                <div>
                    <h4>{{ $source ? (\App\Models\StudentLifecycleChange::typeOptions()[$source->type] ?? 'Student change') : 'Change source unavailable' }} · {{ $outcome }}</h4>
                    <p>{{ $isCurrent ? 'Current result' : 'Earlier result preserved' }} · {{ \App\Actions\Finance\AccountingDisplay::dateTime($entry->occurred_at) }} · {{ $entry->user?->getFilamentName() ?? 'Recorded staff member unavailable' }}</p>
                    <p>{{ $entry->payload['reason'] }}</p>
                    @if ($entry->payload['financial_record_id'] ?? null)
                        <p>Linked {{ str(class_basename($entry->payload['financial_record_type']))->headline() }} #{{ $entry->payload['financial_record_id'] }}</p>
                    @endif
                </div>
                @if ($isCurrent && $source && $reviewAccountAvailable)
                    {{ ($this->getAction('recordLifecycleReview', isMounting: false))(['review' => $entry->related_record_id])->label('Correct review result')->color('gray')->button() }}
                @endif
            </div>
        @empty
            <p>No review result has been recorded for this enrollment.</p>
        @endforelse
        @if ($reviewHistory->hasPages())<x-record-collection-footer :paginator="$reviewHistory" label="review results" />@endif
    </div>
</section>
