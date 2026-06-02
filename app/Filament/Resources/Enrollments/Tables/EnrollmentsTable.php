<?php

namespace App\Filament\Resources\Enrollments\Tables;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Actions\Finance\PaymentConfirmationService;
use App\Models\Enrollment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
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
                TextColumn::make('studentProfile.education_level')
                    ->label('Level')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) $state))
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
                    ->label('Year/Grade')
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
                EditAction::make(),
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

                DB::transaction(function () use ($record, $actor): void {
                    $record->studentProfile()->update([
                        'hard_copy_received' => true,
                        'last_status_changed_at' => now(),
                    ]);

                    DB::table('activity_log')->insert([
                        'log_name' => 'enrollment_registrar',
                        'description' => 'Registrar confirmed physical document submission.',
                        'subject_type' => Enrollment::class,
                        'subject_id' => $record->id,
                        'event' => 'hard_copy_received',
                        'causer_type' => User::class,
                        'causer_id' => $actor->id,
                        'properties' => json_encode([
                            'student_profile_id' => $record->student_profile_id,
                        ], JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });

                Notification::make()
                    ->title('Hard-copy submission marked as received')
                    ->success()
                    ->send();
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
                    ->options([
                        'cash' => 'Cash',
                        'gcash_manual' => 'GCash Manual',
                        'bank_transfer' => 'Bank Transfer',
                        'paymongo_reconciled' => 'PayMongo Reconciled',
                    ])
                    ->default('cash'),
                TextInput::make('payment_reference')
                    ->label('Reference Number')
                    ->maxLength(255),
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
                        confirmedAt: CarbonImmutable::now(config('app.timezone')),
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
