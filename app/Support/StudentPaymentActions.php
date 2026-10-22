<?php

namespace App\Support;

use App\Models\Payment;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

final class StudentPaymentActions
{
    public static function proof(Action $action): Action
    {
        return $action
            ->modalHeading('Submit payment proof')
            ->modalDescription('Send your receipt for Accounting review. Your balance changes only after verification.')
            ->modalSubmitActionLabel('Submit proof')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalAlignment(Alignment::Start)
            ->modalFooterActionsAlignment(Alignment::End)
            ->stickyModalHeader()->stickyModalFooter()
            ->schema([
                FileUpload::make('evidence')->label('Receipt')->storeFiles(false)
                    ->helperText('PDF, JPG or PNG · up to 10 MB. Private to you and authorized school staff.')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)->required()
                    ->validationMessages(['required' => 'Choose a PDF, JPG or PNG receipt.']),
                Grid::make(2)->schema([
                    TextInput::make('claimed_amount')->label('Amount paid')->numeric()->minValue(0.01)->prefix('PHP')->required()
                        ->helperText('Use the amount on your receipt.')
                        ->validationMessages(['min' => 'Enter an amount greater than zero.']),
                    Select::make('channel')->label('Payment method')->options(Payment::manualConfirmationChannelOptions())->required(),
                ]),
                Grid::make(2)->schema([
                    DateTimePicker::make('paid_at')->label('Date and time paid')->timezone(config('app.display_timezone'))
                        ->helperText(config('app.display_timezone'))->required()->seconds(false)
                        ->maxDate(fn () => now(config('app.display_timezone'))->addMinutes(5)),
                    TextInput::make('payment_reference')->label('Payment reference (optional)')->maxLength(255)
                        ->placeholder('If shown on your receipt'),
                ]),
            ]);
    }

    /** @param Closure(): array{amount:?string,obligations:array} $checkout */
    public static function online(Action $action, Closure $checkout): Action
    {
        return $action->modal()
            ->modalHeading('Continue to online payment?')
            ->modalIcon(null)
            ->modalAlignment(Alignment::Start)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitActionLabel('Continue to PayMongo')
            ->modalCancelActionLabel('Not now')
            ->modalContent(fn (): View => view('filament.student.pages.online-payment-confirmation', ['checkout' => $checkout()]));
    }
}
