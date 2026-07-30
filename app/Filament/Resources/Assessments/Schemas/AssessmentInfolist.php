<?php

namespace App\Filament\Resources\Assessments\Schemas;

use App\Actions\Finance\StudentAccountPresenter;
use App\Models\Assessment;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use RuntimeException;

class AssessmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student Account')
                    ->description('One operational view of the assessment, posted payments, remaining balance, finance gate, and supporting evidence.')
                    ->schema([
                        TextEntry::make('account_student_number')
                            ->label('Student No.')
                            ->state(fn (Assessment $record): string => self::account($record)['student_number']),
                        TextEntry::make('account_student_name')
                            ->label('Student')
                            ->state(fn (Assessment $record): string => self::account($record)['student_name']),
                        TextEntry::make('account_program')
                            ->label('Program')
                            ->state(fn (Assessment $record): string => self::account($record)['program']),
                        TextEntry::make('account_year_level')
                            ->label('Year Level')
                            ->state(fn (Assessment $record): string => self::account($record)['year_level']),
                        TextEntry::make('account_section')
                            ->label('Section')
                            ->state(fn (Assessment $record): string => self::account($record)['section']),
                        TextEntry::make('account_term')
                            ->label('Term')
                            ->state(fn (Assessment $record): string => self::account($record)['term']),
                        TextEntry::make('account_state')
                            ->label('Account State')
                            ->state(fn (Assessment $record): string => self::account($record)['assessment_state'])
                            ->badge(),
                    ])
                    ->columns(4),
                Section::make('Current Position')
                    ->description('Assessment is the approved charge calculation. Account Activity is the append-only posting history that changes the balance.')
                    ->schema([
                        TextEntry::make('account_assessment_total')
                            ->label('Assessment Total')
                            ->state(fn (Assessment $record): string => self::account($record)['assessment_total']),
                        TextEntry::make('account_posted_payments')
                            ->label('Posted Payments')
                            ->state(fn (Assessment $record): string => self::account($record)['posted_payments']),
                        TextEntry::make('account_remaining_balance')
                            ->label('Remaining Balance')
                            ->state(fn (Assessment $record): string => self::account($record)['remaining_balance']),
                        TextEntry::make('account_current_due')
                            ->label('Current Amount Due')
                            ->state(fn (Assessment $record): string => self::account($record)['current_due'])
                            ->helperText(fn (Assessment $record): string => self::account($record)['current_due_source']),
                        TextEntry::make('account_payment_status')
                            ->label('Payment Status')
                            ->state(fn (Assessment $record): string => self::account($record)['payment_status'])
                            ->badge(),
                        TextEntry::make('account_finance_gate')
                            ->label('Finance Gate')
                            ->state(fn (Assessment $record): string => self::account($record)['finance_gate_status'])
                            ->helperText(fn (Assessment $record): string => self::account($record)['finance_gate_source'])
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Cleared' ? 'success' : 'warning'),
                        TextEntry::make('account_verification')
                            ->label('Payment Verification')
                            ->state(fn (Assessment $record): string => self::account($record)['verification_status']),
                        TextEntry::make('account_or_mapping')
                            ->label('Official Receipt')
                            ->state(fn (Assessment $record): string => self::account($record)['or_mapping_state']),
                    ])
                    ->columns(4),
                Section::make('Next Action')
                    ->schema([
                        TextEntry::make('account_responsible_office')
                            ->label('Responsible Office')
                            ->state(fn (Assessment $record): string => self::account($record)['responsible_office']),
                        TextEntry::make('account_next_action')
                            ->label('What to do next')
                            ->state(fn (Assessment $record): string => self::account($record)['next_action'])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Assessment Charges')
                    ->description('The approved calculation behind the account total.')
                    ->schema([
                        RepeatableEntry::make('account_charge_lines')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['charge_lines'])
                            ->schema([
                                TextEntry::make('description')->label('Charge'),
                                TextEntry::make('quantity')->label('Qty'),
                                TextEntry::make('rate')->label('Rate'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Payment Schedule')
                    ->schema([
                        RepeatableEntry::make('account_schedule_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['schedule_rows'])
                            ->schema([
                                TextEntry::make('category')->label('Due Category'),
                                TextEntry::make('due_date')->label('Due Date'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('state')->label('State')->badge(),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Account Activity')
                    ->description('Posted charges, payments, credits, adjustments, and reversals. Entries are retained; corrections are appended.')
                    ->schema([
                        RepeatableEntry::make('account_ledger_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['ledger_rows'])
                            ->schema([
                                TextEntry::make('posted_at')->label('Posted'),
                                TextEntry::make('direction')->label('Effect')->badge(),
                                TextEntry::make('category')->label('Category'),
                                TextEntry::make('description')->label('Description'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Payment Attempts')
                    ->description('Checkout attempts are evidence states; they do not affect the balance until a verified payment is posted.')
                    ->schema([
                        RepeatableEntry::make('account_attempt_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['attempt_rows'])
                            ->schema([
                                TextEntry::make('reference')->label('Reference'),
                                TextEntry::make('provider')->label('Provider'),
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Payments and OR Reconciliation')
                    ->description('Only verified payments with posted account activity are listed. An acknowledgement is not an official receipt.')
                    ->schema([
                        RepeatableEntry::make('account_acknowledgement_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['acknowledgement_rows'])
                            ->schema([
                                TextEntry::make('paid_at')->label('Paid'),
                                TextEntry::make('reference')->label('Payment Reference'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('or_mapping')->label('OR Mapping'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Payment Allocations')
                    ->description('How each verified payment was applied to eligible account items. One payment may create several allocation-linked ledger postings.')
                    ->schema([
                        RepeatableEntry::make('account_allocation_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['allocation_rows'])
                            ->schema([
                                TextEntry::make('payment_reference')->label('Payment Reference'),
                                TextEntry::make('target')->label('Applied To'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Adjustments and Reversals')
                    ->description('Accounting corrections preserve the original entry and add a linked balancing entry with an audit reason.')
                    ->schema([
                        RepeatableEntry::make('account_adjustment_rows')
                            ->hiddenLabel()
                            ->state(fn (Assessment $record): mixed => self::account($record)['adjustment_rows'])
                            ->schema([
                                TextEntry::make('posted_at')->label('Posted'),
                                TextEntry::make('direction')->label('Effect')->badge(),
                                TextEntry::make('category')->label('Category'),
                                TextEntry::make('description')->label('Reason'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
                Section::make('Financial Accommodation')
                    ->schema([
                        TextEntry::make('account_accommodation_status')
                            ->label('Status')
                            ->state(fn (Assessment $record): mixed => data_get(self::account($record), 'accommodation_summary.status')),
                        TextEntry::make('account_accommodation_basis')
                            ->label('Basis')
                            ->state(fn (Assessment $record): mixed => data_get(self::account($record), 'accommodation_summary.basis')),
                        TextEntry::make('account_accommodation_covered')
                            ->label('Covered Amount')
                            ->state(fn (Assessment $record): mixed => data_get(self::account($record), 'accommodation_summary.covered_amount')),
                        TextEntry::make('account_accommodation_due')
                            ->label('Next Due')
                            ->state(fn (Assessment $record): mixed => data_get(self::account($record), 'accommodation_summary.next_due')),
                        TextEntry::make('account_accommodation_effects')
                            ->label('Approved Effects')
                            ->state(fn (Assessment $record): mixed => data_get(self::account($record), 'accommodation_summary.approved_effects'))
                            ->listWithLineBreaks()
                            ->columnSpanFull(),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    /** @return array<string, mixed> */
    private static function account(Assessment $assessment): array
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new RuntimeException('An authenticated user is required to view a student account.');
        }

        return app(StudentAccountPresenter::class)->present($assessment, $actor);
    }
}
