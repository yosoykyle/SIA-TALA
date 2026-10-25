@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php($queueUrl = fn (string $key): string => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $key]))
<div class="accounting-workbench">
    <nav class="accounting-work-queues" aria-label="Accounting work queues">
        <a href="{{ $queueUrl('payment_proof') }}" class="accounting-queue accounting-queue--lead tala-task-surface">
            <span class="accounting-queue-icon"><x-filament::icon icon="heroicon-o-credit-card" /></span>
            <span class="accounting-queue-count">{{ $counts['proof'] }}</span>
            <span class="accounting-queue-title">Payment proof reviews</span>
            <span class="accounting-queue-description">Accounts with submitted payment records awaiting review.</span>
            <x-filament::button tag="span" color="primary" class="accounting-queue-action">Review payment proof <x-filament::icon icon="heroicon-m-chevron-right" /></x-filament::button>
        </a>
        <a href="{{ $queueUrl('assessment_required') }}" class="accounting-queue accounting-queue--assessment tala-task-surface">
            <span class="accounting-queue-icon"><x-filament::icon icon="heroicon-o-document-text" /></span>
            <span class="accounting-queue-count">{{ $counts['assessment'] }}</span>
            <span class="accounting-queue-title">Assessment work</span>
            <span class="accounting-queue-description">Accounts with charges, recorded changes or retained payments to review.</span>
            <x-filament::button tag="span" color="gray" class="accounting-queue-action">Review assessments <x-filament::icon icon="heroicon-m-chevron-right" /></x-filament::button>
        </a>
        <a href="{{ $queueUrl('payment_exceptions') }}" class="accounting-queue accounting-queue--provider tala-task-surface">
            <span class="accounting-queue-icon"><x-filament::icon icon="heroicon-o-exclamation-circle" /></span>
            <span class="accounting-queue-count">{{ $counts['provider'] }}</span>
            <span class="accounting-queue-title">Online payment reviews</span>
            <span class="accounting-queue-description">Accounts with failed or unmatched online payment records.</span>
            <x-filament::button tag="span" color="gray" class="accounting-queue-action">Resolve online payments <x-filament::icon icon="heroicon-m-chevron-right" /></x-filament::button>
        </a>
    </nav>
    <div class="accounting-workbench-note">
        <p><x-filament::icon icon="heroicon-o-information-circle" /> An account may appear in more than one queue.</p>
    </div>
    <section class="accounting-search-panel tala-task-surface" aria-labelledby="accounting-records-heading">
        <h2 id="accounting-records-heading">Find a student account</h2>
        <form method="get" action="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index') }}" class="accounting-record-search">
            <input type="hidden" name="tab" value="accounts">
            <label for="accounting-record-search">Name or student number</label>
            <x-filament::input.wrapper><x-filament::input type="search" name="search" id="accounting-record-search" placeholder="Name or student number" /></x-filament::input.wrapper>
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Open account records</x-filament::button>
        </form>
    </section>
</div>
<x-filament-actions::modals />
