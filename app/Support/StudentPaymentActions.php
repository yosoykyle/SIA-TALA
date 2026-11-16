<?php

namespace App\Support;

use App\Actions\Finance\SchoolPaymentChannels;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

final class StudentPaymentActions
{
    public static function submitReceiptAction(Action $action): Action
    {
        return $action
            ->visible(fn (): bool => app(SchoolPaymentChannels::class)->hasEnabledDigitalChannels())
            ->authorize(fn (): bool => app(SchoolPaymentChannels::class)->hasEnabledDigitalChannels())
            ->modalHeading('Submit receipt')
            ->modalDescription('Send your transfer receipt or screenshot for Accounting review. Your balance changes only after verification.')
            ->modalSubmitActionLabel('Submit receipt')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalAlignment(Alignment::Start)
            ->modalFooterActionsAlignment(Alignment::End)
            ->stickyModalHeader()->stickyModalFooter()
            ->schema([
                SchemaView::make('filament.student.pages.how-to-pay')
                    ->viewData(fn (): array => [
                        'display' => app(SchoolPaymentChannels::class)->forDisplay(),
                        'channels' => app(SchoolPaymentChannels::class)->enabledChannels(),
                        'variant' => 'compact',
                        'compact' => true,
                    ]),
                FileUpload::make('evidence')->label('Transfer receipt or screenshot')->storeFiles(false)
                    ->helperText('PDF, JPG or PNG · up to 10 MB. Private to you and authorized school staff.')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->required()
                    ->validationMessages(['required' => 'Choose a PDF, JPG or PNG receipt.']),
                Grid::make(2)->schema([
                    TextInput::make('claimed_amount')->label('Amount paid')->numeric()->minValue(0.01)->prefix('PHP')->required()
                        ->helperText('Use the amount on your receipt.')
                        ->validationMessages(['min' => 'Enter an amount greater than zero.']),
                    Radio::make('channel')
                        ->label('Payment method')
                        ->options(fn (): array => app(SchoolPaymentChannels::class)->digitalMethodOptions())
                        ->default(function (): ?string {
                            $options = app(SchoolPaymentChannels::class)->digitalMethodOptions();

                            return count($options) === 1 ? array_key_first($options) : null;
                        })
                        ->inline()
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('channel_account_id', null))
                        ->required(),
                ]),
                Select::make('channel_account_id')
                    ->label('School account used')
                    ->placeholder('Select the specific school account you paid to')
                    ->options(fn (Get $get): array => filled($get('channel'))
                        ? app(SchoolPaymentChannels::class)->accountOptionsForGroup((string) $get('channel'))
                        : [])
                    ->visible(fn (Get $get): bool => filled($get('channel'))
                        && count(app(SchoolPaymentChannels::class)->accountOptionsForGroup((string) $get('channel'))) > 1)
                    ->required(fn (Get $get): bool => filled($get('channel'))
                        && count(app(SchoolPaymentChannels::class)->accountOptionsForGroup((string) $get('channel'))) > 1)
                    ->live(),
                Grid::make(2)->schema([
                    DateTimePicker::make('paid_at')->label('Date and time paid')->timezone(config('app.display_timezone'))
                        ->helperText(config('app.display_timezone'))->required()->seconds(false)
                        ->maxDate(fn () => now(config('app.display_timezone'))->addMinutes(5)),
                    TextInput::make('payment_reference')
                        ->label(fn (Get $get): string => self::referenceGuidanceForForm($get)['label'])
                        ->maxLength(255)
                        ->placeholder(fn (Get $get): string => self::referenceGuidanceForForm($get)['placeholder'])
                        ->helperText(fn (Get $get): string => self::referenceGuidanceForForm($get)['hint']),
                ]),
            ]);
    }

    public static function proof(Action $action): Action
    {
        return self::submitReceiptAction($action);
    }

    /**
     * Resolve canonical evidence channel and immutable account snapshot from submitted modal data.
     *
     * @param  array<string, mixed>  $data
     * @return array{channel: string, snapshot: array<string, mixed>|null}
     */
    public static function resolveSubmissionChannel(array $data): array
    {
        $channels = app(SchoolPaymentChannels::class);
        $selectedGroup = (string) ($data['channel'] ?? '');
        $selectedAccountId = filled($data['channel_account_id'] ?? null)
            ? (string) $data['channel_account_id']
            : null;

        return [
            'channel' => $channels->resolveEvidenceChannel($selectedGroup, $selectedAccountId),
            'snapshot' => $channels->resolveAccountSnapshot($selectedGroup, $selectedAccountId),
        ];
    }

    /** @return array{label: string, placeholder: string, hint: string} */
    private static function referenceGuidanceForForm(Get $get): array
    {
        $channel = filled($get('channel')) ? (string) $get('channel') : null;

        if ($channel === null) {
            return SchoolPaymentChannels::referenceGuidance(null, null);
        }

        $accountId = filled($get('channel_account_id')) ? (string) $get('channel_account_id') : null;
        $snapshot = app(SchoolPaymentChannels::class)->resolveAccountSnapshot($channel, $accountId);

        return SchoolPaymentChannels::referenceGuidance($channel, $snapshot['provider_or_bank'] ?? null);
    }
}
