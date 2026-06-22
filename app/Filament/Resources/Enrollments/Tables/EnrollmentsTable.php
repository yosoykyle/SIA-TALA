<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Enrollment\EnrollmentHardCopyReceiptService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class EnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'studentProfile.program', 'term', 'section']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('section.name')
                    ->label('Section')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending_payment',
                        'success' => 'pre_enrolled',
                        'info' => 'officially_enrolled',
                        'danger' => 'ineligible',
                        'gray' => 'completed',
                    ])
                    ->searchable(),
                TextColumn::make('student_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('studentProfile.current_balance')
                    ->label('Balance')
                    ->money('PHP')
                    ->sortable(),
                IconColumn::make('studentProfile.hard_copy_received')
                    ->label('Hard Copy')
                    ->boolean(),
                TextColumn::make('lis_status')
                    ->label('LIS')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending_payment' => 'Pending Payment',
                        'pre_enrolled' => 'Pre-Enrolled',
                        'officially_enrolled' => 'Officially Enrolled',
                        'ineligible' => 'Ineligible',
                        'completed' => 'Completed',
                    ]),
                SelectFilter::make('student_type')
                    ->options([
                        'new' => 'New/Freshmen',
                        'transferee' => 'Transferee',
                        'regular' => 'Regular',
                        'irregular' => 'Irregular',
                        'returnee' => 'Returnee',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('statement')
                    ->label('SOA')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Enrollment $record): string => route('finance.statements.show', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Enrollment $record): bool => auth()->user()?->can('viewStatement', $record) ?? false),
                self::markHardCopyReceivedAction(),
                self::assessAction(),
                self::confirmPaymentAction(),
            ])
            ->toolbarActions([]);
    }

    private static function markHardCopyReceivedAction(): Action
    {
        return Action::make('markHardCopyReceived')
            ->label('Mark Hard Copy')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (Enrollment $record): bool => auth()->user()?->can('markHardCopyReceived', $record) ?? false)
            ->action(function (Enrollment $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    app(EnrollmentHardCopyReceiptService::class)->markReceived($record, $actor);

                    Notification::make()
                        ->title('Hard-copy submission marked as received')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Hard-copy confirmation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function assessAction(): Action
    {
        return Action::make('assess')
            ->label('Run Assessment')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Enrollment $record): bool => auth()->user()?->can('assess', $record) ?? false)
            ->action(function (Enrollment $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    $summary = app(EnrollmentAssessmentService::class)->assess($record->id, $actor);

                    Notification::make()
                        ->title($summary['already_assessed'] ? 'Assessment already exists' : 'Assessment posted')
                        ->body('Net assessment: PHP '.$summary['net_assessment'].'; discount: PHP '.$summary['discount_amount'])
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Assessment failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function confirmPaymentAction(): Action
    {
        return Action::make('confirmPayment')
            ->label('Confirm Payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->schema([
                TextInput::make('amount')
                    ->label('Amount Paid')
                    ->required()
                    ->numeric()
                    ->minValue(0.01),
                Select::make('channel')
                    ->required()
                    ->options(Payment::manualConfirmationChannelOptions())
                    ->default('cash'),
                TextInput::make('payment_reference')
                    ->label('Reference Number')
                    ->required()
                    ->maxLength(255),
                DateTimePicker::make('confirmed_at')
                    ->label('Payment Date')
                    ->required()
                    ->default(fn (): CarbonImmutable => CarbonImmutable::now(config('app.timezone')))
                    ->maxDate(fn (): CarbonImmutable => CarbonImmutable::now(config('app.timezone')))
                    ->seconds(false),
            ])
            ->modalSubmitActionLabel('Confirm Payment')
            ->visible(fn (Enrollment $record): bool => auth()->user()?->can('confirmPayment', $record) ?? false)
            ->action(function (Enrollment $record, array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    $summary = app(PaymentConfirmationService::class)->confirmManualPayment(
                        enrollmentId: $record->id,
                        amount: (string) $data['amount'],
                        channel: (string) $data['channel'],
                        paymentReference: isset($data['payment_reference']) ? (string) $data['payment_reference'] : null,
                        actor: $actor,
                        confirmedAt: CarbonImmutable::parse((string) $data['confirmed_at'], config('app.timezone')),
                    );

                    Notification::make()
                        ->title($summary['finance_cleared'] ? 'Payment confirmed and finance cleared' : 'Payment confirmed')
                        ->body('Current balance: PHP '.$summary['current_balance'])
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Payment confirmation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
