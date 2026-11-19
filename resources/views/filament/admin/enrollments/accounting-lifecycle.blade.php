@assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
<div class="registrar-case accounting-lifecycle-reviews">
    <section id="student-changes" @class(['accounting-review-task' => ! $this->isAccountingWorkspace(), 'tala-task-surface' => ! $this->isAccountingWorkspace(), 'accounting-workspace-review' => $this->isAccountingWorkspace()]) aria-labelledby="pending-student-changes-heading" wire:loading.class="opacity-70">
        <h2 id="pending-student-changes-heading">{{ $this->isAccountingWorkspace() ? 'Review student status changes' : 'Pending student status changes' }} @if (! $this->isAccountingWorkspace() && $pendingReviews->total() > 0)<x-filament::badge color="warning">{{ $pendingReviews->total() }}</x-filament::badge>@endif</h2>
        <p>Check the applied status change and record Accounting’s review.</p>
        @if (! $reviewAccountAvailable)
            <div class="accounting-source-warning" id="accounting-change-blocker" role="status">
                <h3>Term account unavailable</h3>
                <p>Ask Accounting and Registrar to restore this term’s account before recording the review. Reviews stay pending; no balance is assumed.</p>
            </div>
        @else
            <p>Record any fee adjustment, approved coverage or refund in the account before completing the review.</p>
        @endif
        <div class="accounting-obligations">
            @forelse ($pendingReviews as $review)
                @php $source = $review->lifecycleReviewSource; @endphp
                <div wire:key="pending-student-change-{{ $review->id }}">
                    <div>
                        <h3>{{ $source ? (\App\Models\StudentLifecycleChange::typeOptions()[$source->type] ?? 'Applied student change') : 'Change source unavailable' }}</h3>
                        @if ($source)<p>Effective {{ \App\Actions\Finance\AccountingDisplay::date($source->effective_on) }}</p>
                        @else<p id="accounting-change-source-{{ $review->id }}">Ask Registrar to restore or investigate this source. The review stays pending.</p>@endif
                        <x-filament::badge color="warning">Review pending</x-filament::badge>
                    </div>
                    <div class="registrar-case-task-actions">
                        @if ($source)
                            @can('view', $source)
                                <x-filament::link :href="\App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource::getUrl('index', ['change' => $source->id, 'account' => $case->id, 'queue' => 'student_changes'])">View change</x-filament::link>
                            @endcan
                        @endif
                        @if ($source && $reviewAccountAvailable)
                            @php $reviewAction = ($this->getAction('recordLifecycleReview', isMounting: false))(['review' => $review->id])->button(); @endphp
                            {{ $reviewAction }}
                        @else
                            <x-filament::button disabled color="gray" :aria-describedby="$reviewAccountAvailable ? 'accounting-change-source-'.$review->id : 'accounting-change-blocker'">Record review result</x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                <x-servitech-empty-state heading="No student status changes waiting for review" heading-tag="h3" description="Recorded reviews remain in the history below. Open the account for its current financial position." />
            @endforelse
            @if ($pendingReviews->hasPages())<x-record-collection-footer :paginator="$pendingReviews" label="changes" />@endif
        </div>
        <p class="accounting-review-note">Recording a review does not change fees or payment clearance.</p>
    </section>
    @if (! $this->isAccountingWorkspace() || $reviewHistory->total() > 0)
        @include('filament.admin.enrollments.accounting-lifecycle-history')
    @endif
</div>
