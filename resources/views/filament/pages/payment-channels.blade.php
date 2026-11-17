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
                            <h3 id="payment-channels-preview-heading">Students and applicants &ldquo;How to pay&rdquo; preview</h3>
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
                        <div class="payment-channels-reason-field">
                            <label for="payment-channels-reason" class="payment-channels-reason-label">
                                Reason for update (optional)
                            </label>
                            <div @class(['fi-input-wrp', 'fi-invalid' => $errors->has('data.reason')])>
                                <input
                                    id="payment-channels-reason"
                                    type="text"
                                    maxlength="255"
                                    wire:model="data.reason"
                                    placeholder="e.g. Updated afternoon hours"
                                    @if ($errors->has('data.reason'))
                                        aria-invalid="true"
                                        aria-describedby="payment-channels-reason-error"
                                    @endif
                                    class="fi-input"
                                />
                            </div>
                            @error('data.reason')
                                <p id="payment-channels-reason-error" class="fi-fo-field-wrp-error-message">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-filament::button
                            type="submit"
                            icon="heroicon-o-check-circle"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="payment-channels-save-btn"
                        >
                            <span wire:loading.remove wire:target="save">Save payment channels</span>
                            <span wire:loading wire:target="save">Saving payment channels…</span>
                        </x-filament::button>

                        <p class="payment-channels-actions__note">
                            Versioned in the governance audit log and applied immediately to How to pay.
                        </p>
                    </div>
                </div>
            </x-sticky-companion>
        </form>
    </div>
</x-filament-panels::page>
