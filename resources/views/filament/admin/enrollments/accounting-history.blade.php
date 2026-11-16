@php
    $lifecycleHistory = $this->lifecycleReviewData();
    $hasVerifiedPayments = $account?->payments->contains(\App\Actions\Finance\AccountingDisplay::isVerifiedPayment(...)) ?? false;
@endphp
<section class="accounting-history-ledger" aria-labelledby="account-history-heading">
    <header class="accounting-history-heading">
        <div><h2 id="account-history-heading">Account history</h2><p>Payments, charges and supporting records for this account.</p></div>
        @if ($hasVerifiedPayments){{ $this->getAction('exportTermAccount', isMounting: false)->color('gray') }}@endif
    </header>
    @if ($account === null)
        <p class="accounting-history-note">No charges or payments recorded yet; reviewed student changes appear below.</p>
    @elseif (! $hasVerifiedPayments)
        <p class="accounting-history-note">No verified payments recorded.</p>
    @endif
    @forelse ($historyGroups as $kind => $group)
        <section class="accounting-history-group" aria-labelledby="account-history-{{ $kind }}-heading">
            <h3 id="account-history-{{ $kind }}-heading"><x-filament::icon :icon="$group['icon']" aria-hidden="true" />{{ $group['title'] }}</h3>
            <x-data-table :label="$group['title'].' · newest first · Asia/Manila'" class="accounting-history-table" records>
                <thead><tr><th scope="col">Recorded on</th><th scope="col">{{ $group['recordLabel'] }}</th>@if ($group['amountLabel'] !== '')<th scope="col" class="tala-data-table-number">{{ $group['amountLabel'] }}</th>@endif</tr></thead>
                <tbody>
                    @foreach ($group['records'] as $entry)
                        @include('filament.admin.enrollments.accounting-history-entry', ['record' => $entry['record'], 'kind' => $entry['kind']])
                    @endforeach
                </tbody>
            </x-data-table>
            @if ($group['records']->hasPages())<x-record-collection-footer :paginator="$group['records']" :label="str($group['recordLabel'])->lower()->plural()->toString()" />@endif
        </section>
    @empty
        @if ($lifecycleHistory['reviewHistory']->total() === 0)<x-servitech-empty-state heading="No account history yet" heading-tag="h3" description="Recorded payments, assessments and supporting records will appear here." illustrated />@endif
    @endforelse
    @if ($lifecycleHistory['reviewHistory']->total() > 0)
        @include('filament.admin.enrollments.accounting-lifecycle-history', $lifecycleHistory)
    @endif
    @if ($account !== null && ($hasVerifiedPayments || $account->coverages->isNotEmpty()))
        <section class="accounting-history-corrections" aria-labelledby="account-history-corrections">
            <h3 id="account-history-corrections">Corrections</h3>
            <p>Record an authorized correction against its original payment or coverage.</p>
            <div class="accounting-context-actions">
                @if ($hasVerifiedPayments){{ $this->getAction('reversePaymentPosting', isMounting: false)->color('gray') }}@endif
                @if ($account->coverages->isNotEmpty()){{ $this->getAction('reverseApprovedCoverage', isMounting: false)->color('gray') }}@endif
            </div>
        </section>
    @endif
    @if ($proposal)
        <section class="accounting-history-subjects" aria-labelledby="account-history-subjects">
            <h3 id="account-history-subjects">Registration subjects</h3>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'account-history'])
        </section>
    @endif
</section>
