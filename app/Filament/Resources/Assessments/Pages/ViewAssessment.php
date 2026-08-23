<?php

namespace App\Filament\Resources\Assessments\Pages;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\PaymentAllocationService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (mixed $state, Set $set) => $set(
                            'allocations',
                            $this->allocationPreview((string) $state),
                        ))
                        ->required(),
                    Select::make('channel')
                        ->label('Payment Method')
                        ->options(Payment::manualConfirmationChannelOptions())
                        ->default('cash')
                        ->live()
                        ->required(),
                    TextInput::make('payment_reference')
                        ->label('Payment / Evidence Reference')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('or_number')
                        ->label('OR Number')
                        ->helperText(fn (Get $get): string => $get('channel') === 'cash'
                            ? 'Required for cash because the physical OR is issued at payment.'
                            : 'Optional now. Accounting may map the physical OR later.')
                        ->required(fn (Get $get): bool => $get('channel') === 'cash')
                        ->maxLength(255),
                    Repeater::make('allocations')
                        ->label('Payment Allocation')
                        ->helperText('The suggested split applies the current due first. Adjust amounts only when the payment must cover different eligible account items.')
                        ->schema([
                            Select::make('target')
                                ->label('Account Item')
                                ->options(fn (): array => $this->allocationTargetOptions())
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->required(),
                            TextInput::make('amount')
                                ->label('Allocated Amount')
                                ->numeric()
                                ->minValue(0.01)
                                ->required(),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->reorderable(false)
                        ->visible(fn (): bool => count($this->allocationTargetOptions()) > 1),
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
                            allocations: $this->submittedAllocations($data['allocations'] ?? null),
                            orNumber: filled($data['or_number'] ?? null)
                                ? (string) $data['or_number']
                                : null,
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
                Action::make('openPayments')
                    ->label('Payments and OR Reconciliation')
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

    /** @return array<string, string> */
    private function allocationTargetOptions(): array
    {
        $enrollment = $this->allocationEnrollment();

        if (! $enrollment instanceof Enrollment) {
            return [];
        }

        return collect(app(PaymentAllocationService::class)->eligibleTargets($enrollment))
            ->mapWithKeys(fn (array $target): array => [
                $target['target_type'].'|'.$target['target_id'] => $target['description']
                    .' - PHP '.number_format((float) $target['amount'], 2),
            ])
            ->all();
    }

    /** @return list<array{target:string,amount:string}> */
    private function allocationPreview(string $amount): array
    {
        $enrollment = $this->allocationEnrollment();

        if (! $enrollment instanceof Enrollment || blank($amount)) {
            return [];
        }

        try {
            return collect(app(PaymentAllocationService::class)->preview($enrollment, $amount))
                ->map(fn (array $target): array => [
                    'target' => $target['target_type'].'|'.$target['target_id'],
                    'amount' => $target['amount'],
                ])
                ->all();
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * @return list<array{target_type:string,target_id:int,amount:string}>|null
     */
    private function submittedAllocations(mixed $submitted): ?array
    {
        if (! is_array($submitted) || $submitted === []) {
            return null;
        }

        return collect($submitted)
            ->map(function (array $allocation): array {
                [$type, $id] = array_pad(explode('|', (string) ($allocation['target'] ?? ''), 2), 2, null);

                return [
                    'target_type' => (string) $type,
                    'target_id' => (int) $id,
                    'amount' => (string) ($allocation['amount'] ?? ''),
                ];
            })
            ->all();
    }

    private function allocationEnrollment(): ?Enrollment
    {
        $assessment = $this->getRecord();

        return $assessment instanceof Assessment
            ? $assessment->enrollment
            : null;
    }
}
