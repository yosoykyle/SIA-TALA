<?php

namespace App\Filament\Resources\Assessments\Pages;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Resources\PaymentAttempts\PaymentAttemptResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ViewAssessment extends ViewRecord
{
    protected static string $resource = AssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordManualPayment')
                ->label('Record Manual Payment')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('primary')
                ->schema([
                    TextInput::make('amount')
                        ->label('Amount Received')
                        ->numeric()
                        ->minValue(0.01)
                        ->required(),
                    Select::make('channel')
                        ->label('Payment Method')
                        ->options(Payment::manualConfirmationChannelOptions())
                        ->default('cash')
                        ->required(),
                    TextInput::make('payment_reference')
                        ->label('Payment / Evidence Reference')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('or_number')
                        ->label('OR Number')
                        ->required()
                        ->maxLength(255),
                    DateTimePicker::make('paid_at')
                        ->label('Paid At')
                        ->default(now())
                        ->seconds(false)
                        ->required(),
                ])
                ->modalHeading('Record manual cashier payment')
                ->modalSubmitActionLabel('Record payment')
                ->visible(fn (): bool => $this->canRecordManualPayment())
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $assessment = $this->getRecord();

                    if (! $actor instanceof User || ! $assessment instanceof Assessment) {
                        abort(403);
                    }

                    try {
                        app(PaymentConfirmationService::class)->confirmManualPayment(
                            enrollmentId: (int) $assessment->enrollment_id,
                            amount: (string) $data['amount'],
                            channel: (string) $data['channel'],
                            paymentReference: (string) $data['payment_reference'],
                            actor: $actor,
                            confirmedAt: CarbonImmutable::parse((string) $data['paid_at'], config('app.timezone')),
                            orNumber: (string) $data['or_number'],
                        );

                        $this->record = $assessment->refresh();

                        Notification::make()
                            ->title('Manual payment recorded')
                            ->success()
                            ->send();
                    } catch (RuntimeException $exception) {
                        Notification::make()
                            ->title('Manual payment was not recorded')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('activateAssessment')
                ->label('Activate Assessment')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canActivate())
                ->action(function (): void {
                    $actor = auth()->user();
                    $assessment = $this->getRecord();

                    if (! $actor instanceof User || ! $assessment instanceof Assessment) {
                        abort(403);
                    }

                    $this->record = app(EnrollmentAssessmentService::class)->activate($assessment, $actor);

                    Notification::make()
                        ->title('Assessment activated')
                        ->success()
                        ->send();
                }),
            ActionGroup::make([
                Action::make('openAccountActivity')
                    ->label('Account Activity')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (): string => LedgerEntryResource::getUrl('index', [
                        'tableSearch' => $this->studentSearch(),
                    ], panel: 'admin')),
                Action::make('openPaymentAttempts')
                    ->label('Payment Attempts')
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (): string => PaymentAttemptResource::getUrl('index', [
                        'tableSearch' => $this->studentSearch(),
                    ], panel: 'admin')),
                Action::make('openPayments')
                    ->label('Payments and Official Receipts')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->url(fn (): string => PaymentResource::getUrl('index', [
                        'tableSearch' => $this->studentSearch(),
                    ], panel: 'admin')),
                Action::make('openAdjustments')
                    ->label('Adjustments and Reversals')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (): string => AccountingAdjustmentResource::getUrl('index', [
                        'tableSearch' => $this->studentSearch(),
                    ], panel: 'admin')),
                Action::make('openAccommodations')
                    ->label('Financial Accommodations')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url(fn (): string => FinancialAccommodationResource::getUrl('index', [
                        'tableSearch' => $this->studentSearch(),
                    ], panel: 'admin')),
            ])
                ->label('Account Records')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->visible(fn (): bool => auth()->user()?->canProcessPayments() === true),
        ];
    }

    private function studentSearch(): string
    {
        $assessment = $this->getRecord();

        return $assessment instanceof Assessment
            ? (string) $assessment->enrollment?->studentProfile?->student_number
            : '';
    }

    private function canActivate(): bool
    {
        $user = auth()->user();
        $assessment = $this->getRecord();

        return $user instanceof User
            && $assessment instanceof Assessment
            && $assessment->state === Assessment::StateDraft
            && $user->can('activate', $assessment);
    }

    private function canRecordManualPayment(): bool
    {
        $user = auth()->user();
        $assessment = $this->getRecord();

        return $user instanceof User
            && $assessment instanceof Assessment
            && $assessment->state === Assessment::StateActive
            && $user->canProcessPayments();
    }
}
