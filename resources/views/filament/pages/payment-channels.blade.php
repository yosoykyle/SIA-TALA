@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $summary = $this->summaryData();
    $preview = $this->previewData();
@endphp

<x-filament-panels::page>
    <div class="payment-channels-page">
        <section
            @class([
                'tala-task-surface',
                'accounting-overview-strip',
                'payment-channels-summary',
                'payment-channels-summary--warning' => $summary['isWarning'],
                'payment-channels-summary--draft' => $summary['isDirty'] && ! $summary['isWarning'],
                'accounting-overview-strip--active' => ! $summary['isWarning'] && ! $summary['isDirty'] && $summary['enabledCount'] > 0,
            ])
            aria-labelledby="payment-channels-summary-heading"
        >
            <div class="accounting-overview-strip__lead">
                <span class="tala-workspace-card__icon" aria-hidden="true">
                    <x-filament::icon :icon="$summary['isWarning'] ? 'heroicon-o-exclamation-triangle' : ($summary['isDirty'] ? 'heroicon-o-pencil-square' : 'heroicon-o-building-library')" />
                </span>
                <div>
                    <div class="payment-channels-summary__heading-row">
                        <h2 id="payment-channels-summary-heading" aria-live="polite">
                            {{ $summary['headline'] }}
                        </h2>
                    </div>
                    <p class="accounting-overview-strip__meta">{{ $summary['caption'] }}</p>
                </div>
            </div>

            @if ($summary['savedAt'])
                <p class="payment-channels-summary__saved">Last saved {{ $summary['savedAt'] }}</p>
            @endif
        </section>

        @if ($this->isEditing)
            <form wire:submit="save" class="payment-channels-workbench">
                <div class="payment-channels-workbench__editor">
                    {{ $this->form }}
                </div>

                <x-sticky-companion class="payment-channels-workbench__rail" aria-labelledby="payment-channels-preview-heading">
                    <div class="payment-channels-preview-card tala-companion-card">
                        <header class="payment-channels-preview-card__head">
                            <div class="payment-channels-preview-card__title">
                                <span class="tala-workspace-card__icon payment-channels-preview-card__icon" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-o-eye" />
                                </span>
                                <h2 id="payment-channels-preview-heading">Live &ldquo;How to pay&rdquo; preview</h2>
                            </div>
                            @if ($preview['isDirty'])
                                <x-filament::badge color="warning" size="sm">Unsaved</x-filament::badge>
                            @endif
                        </header>

                        <div class="payment-channels-preview-card__body tala-companion-body" tabindex="0" role="region" aria-label="Payment instructions preview">
                            @include('filament.student.pages.how-to-pay', [
                                'display' => $preview['display'],
                                'variant' => 'preview',
                                'surface' => 'accounting-preview',
                            ])
                        </div>

                        <div class="payment-channels-preview-card__footer">
                            <p class="payment-channels-actions__note">
                                @if ($preview['isDirty'])
                                    Preview reflects your unsaved changes. Select <strong>Save payment channels</strong> above to confirm and publish.
                                @else
                                    Live preview of student and applicant instructions. Change any channel on the left to save updates.
                                @endif
                            </p>
                        </div>
                    </div>
                </x-sticky-companion>
            </form>
        @else
            <section class="payment-channels-preview-card payment-channels-preview-only" aria-labelledby="payment-channels-preview-heading">
                <header class="payment-channels-preview-card__head">
                    <div class="payment-channels-preview-card__title">
                        <span class="tala-workspace-card__icon payment-channels-preview-card__icon" aria-hidden="true">
                            <x-filament::icon icon="heroicon-o-eye" />
                        </span>
                        <h2 id="payment-channels-preview-heading">&ldquo;How to pay&rdquo; preview</h2>
                    </div>
                    <x-filament::badge color="gray" size="sm">View only</x-filament::badge>
                </header>

                <div class="payment-channels-preview-card__body" role="region" aria-label="Payment instructions preview">
                    @include('filament.student.pages.how-to-pay', [
                        'display' => $preview['display'],
                        'variant' => 'preview',
                        'surface' => 'accounting-preview',
                    ])
                </div>

                <div class="payment-channels-preview-card__footer">
                    <p class="payment-channels-actions__note">
                        Read-only preview of live payment instructions. Select <strong>Edit payment channels</strong> above to update Bank Transfer, E-Wallets, or Campus Cashier details.
                    </p>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
