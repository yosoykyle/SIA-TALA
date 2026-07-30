<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Actions\Finance\MapOfficialReceiptToPayment;
use App\Actions\Finance\PaymentAcademicContextResolver;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with([
                    'studentProfile.user',
                    'studentProfile.program',
                    'studentProfile.curriculumVersion',
                    'studentProfile.enrollments.term',
                    'studentProfile.enrollments.courseEnrollments.termOffering.curriculumEntry',
                    'studentProfile.enrollments.courseEnrollments.proposedSection.deliveryGroups',
                    'studentProfile.enrollments.courseEnrollments.seatReservations.section.deliveryGroups',
                    'studentProfile.enrollments.gateResults',
                    'term',
                    'paymentAttempt.assessment.enrollment',
                    'ledgerEntry',
                    'verifier',
                ])
                ->withCount('ledgerEntries'))
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.program.code')
                    ->label('Program')
                    ->placeholder('-'),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->state(fn (Payment $record): string => (string) (self::academicContext($record)['curriculum_level_label'] ?? 'Not recorded')),
                TextColumn::make('section')
                    ->label('Section')
                    ->state(fn (Payment $record): string => collect(self::academicContext($record)['section_labels'] ?? [])->implode(', ') ?: 'Not assigned'),
                TextColumn::make('term.label')
                    ->label('Term')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('academic_enrollment')
                    ->label('Enrollment')
                    ->state(fn (Payment $record): string => self::academicEnrollment($record)?->displayLabel() ?? '-')
                    ->formatStateUsing(function (?string $state, Payment $record): string {
                        $enrollment = self::academicEnrollment($record);

                        return $enrollment instanceof Enrollment ? self::enrollmentLabel($enrollment) : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('paymentAttempt.id')
                    ->label('Payment Attempt')
                    ->formatStateUsing(function (?int $state, Payment $record): string {
                        $attempt = $record->paymentAttempt;

                        return $attempt instanceof PaymentAttempt ? self::paymentAttemptLabel($attempt) : '-';
                    })
                    ->placeholder('-'),
                TextColumn::make('ledger_entries_count')
                    ->label('Ledger Postings')
                    ->formatStateUsing(fn (int|string|null $state): string => ((int) $state) > 0
                        ? ((int) $state).' posted'
                        : 'Not posted')
                    ->badge(),
                TextColumn::make('provider_reference')
                    ->label('Reference')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('or_number')
                    ->label('OR Number')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('channel')
                    ->formatStateUsing(fn (?string $state): string => self::paymentChannelLabel($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('evidence_status')
                    ->formatStateUsing(fn (?string $state): string => self::humanizeCode($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('verifier.name')
                    ->label('Verified By')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('evidence_status')
                    ->options([
                        'verified' => 'Verified',
                        'under_review' => 'Under Review',
                        'rejected' => 'Rejected',
                    ]),
                SelectFilter::make('channel')
                    ->options([
                        'cash' => 'Cash',
                        'gcash_manual' => 'GCash Manual',
                        'bank_transfer' => 'Bank Transfer',
                        'paymongo' => 'PayMongo',
                        'paymongo_reconciled' => 'PayMongo Reconciled',
                        'synthetic_acceptance' => 'Acceptance Fixture',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('acknowledgement')
                    ->label('Acknowledgement')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (Payment $record): string => route('finance.payments.acknowledgement', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Payment $record): bool => self::currentUser()?->can('viewAcknowledgement', $record) ?? false),
                Action::make('mapOr')
                    ->label('Map OR')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->color('primary')
                    ->schema([
                        TextInput::make('or_number')
                            ->label('OR Number')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Payment $record, array $data): void {
                        $actor = Auth::user();

                        if (! $actor instanceof User) {
                            abort(403);
                        }

                        try {
                            app(MapOfficialReceiptToPayment::class)->execute(
                                payment: $record,
                                orNumber: (string) $data['or_number'],
                                actor: $actor,
                            );

                            Notification::make()
                                ->title('Official Receipt mapped successfully')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Official Receipt was not mapped')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(fn (Payment $record): bool => self::currentUser()?->can('mapOfficialReceipt', $record) ?? false),
            ])
            ->toolbarActions([]);
    }

    private static function currentUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @return array<string, mixed> */
    private static function academicContext(Payment $payment): array
    {
        return app(PaymentAcademicContextResolver::class)->forPayment($payment);
    }

    private static function academicEnrollment(Payment $payment): ?Enrollment
    {
        return app(PaymentAcademicContextResolver::class)->enrollment($payment);
    }

    private static function enrollmentLabel(Enrollment $enrollment): string
    {
        $enrollment->loadMissing('term');

        return collect([
            "#{$enrollment->id}",
            $enrollment->term->label,
            self::humanizeCode($enrollment->status),
        ])->implode(' - ');
    }

    private static function paymentAttemptLabel(PaymentAttempt $attempt): string
    {
        return collect([
            "#{$attempt->id}",
            self::paymentProviderLabel($attempt->provider),
            self::paymentChannelLabel($attempt->channel),
            self::humanizeCode($attempt->status),
            'Amount: '.number_format((float) $attempt->amount, 2),
        ])->implode(' - ');
    }

    private static function paymentChannelLabel(?string $channel): string
    {
        return [
            'cash' => 'Cash',
            'gcash_manual' => 'GCash Manual',
            'bank_transfer' => 'Bank Transfer',
            'paymongo' => 'PayMongo',
            'paymongo_reconciled' => 'PayMongo Reconciled',
            'online_checkout' => 'Online Checkout',
            'synthetic_acceptance' => 'Acceptance Fixture',
        ][$channel] ?? self::humanizeCode($channel);
    }

    private static function paymentProviderLabel(?string $provider): string
    {
        return [
            'paymongo' => 'PayMongo',
            'synthetic_acceptance' => 'Acceptance Fixture',
        ][$provider] ?? self::humanizeCode($provider);
    }

    private static function humanizeCode(?string $code): string
    {
        return filled($code) ? Str::headline($code) : '-';
    }
}
