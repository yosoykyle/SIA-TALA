<?php

namespace App\Filament\Student\Pages;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\Integrations\Payments\CreatePaymentCheckoutSession;
use App\Actions\Integrations\Payments\PaymentCheckoutException;
use App\Support\DecimalMoney;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;
use Throwable;

class Finance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Finance';

    protected static ?string $title = 'Finance';

    /**
     * @var array<string, mixed>
     */
    public array $finance = [];

    public function mount(): void
    {
        $actor = auth()->user();

        abort_unless($actor !== null, 403);

        $this->finance = app(FinanceEvidenceService::class)->studentFinance($actor);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->state($this->finance['state'] ?? [])
            ->components([
                Section::make('Finance Status')
                    ->schema([
                        TextEntry::make('availability_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => $state === 'Available' ? 'success' : 'warning'),
                        TextEntry::make('term')->label('Term'),
                        TextEntry::make('notice')
                            ->label('Notice')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Assessment Summary')
                    ->schema([
                        TextEntry::make('student_number')->label('Student No.'),
                        TextEntry::make('student_name')->label('Full Name'),
                        TextEntry::make('program')->label('Program'),
                        TextEntry::make('assessment_total')->label('Assessment Total'),
                        TextEntry::make('required_downpayment')->label('Required Downpayment'),
                        TextEntry::make('posted_payments')->label('Posted Payments'),
                        TextEntry::make('ledger_balance')->label('Ledger-Derived Balance'),
                        TextEntry::make('current_due')->label('Current Amount Due'),
                        TextEntry::make('current_due_source')->label('Due Category'),
                        TextEntry::make('payment_status')->label('Payment Status'),
                        TextEntry::make('or_mapping_state')->label('OR Mapping State'),
                    ])
                    ->columns(4),
                Section::make('Charge Lines')
                    ->schema([
                        RepeatableEntry::make('charge_lines')
                            ->label('Charges')
                            ->schema([
                                TextEntry::make('description')->label('Description'),
                                TextEntry::make('quantity')->label('Qty'),
                                TextEntry::make('rate')->label('Rate'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Payment Schedule')
                    ->schema([
                        RepeatableEntry::make('schedule_rows')
                            ->label('Schedule')
                            ->schema([
                                TextEntry::make('category')->label('Category'),
                                TextEntry::make('due_date')->label('Due Date'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('state')->label('State'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Ledger and Payments')
                    ->schema([
                        RepeatableEntry::make('ledger_rows')
                            ->label('Posted Ledger Entries')
                            ->schema([
                                TextEntry::make('posted_at')->label('Posted'),
                                TextEntry::make('direction')->label('Direction'),
                                TextEntry::make('category')->label('Category'),
                                TextEntry::make('description')->label('Description'),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                        RepeatableEntry::make('attempt_rows')
                            ->label('Payment Attempts')
                            ->schema([
                                TextEntry::make('reference')->label('Reference'),
                                TextEntry::make('provider')->label('Provider'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => match (strtolower($state)) {
                                        'paid' => 'success',
                                        'failed', 'expired' => 'danger',
                                        'under review' => 'warning',
                                        default => 'info',
                                    }),
                                TextEntry::make('amount')->label('Amount'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                        RepeatableEntry::make('acknowledgement_rows')
                            ->label('Available Acknowledgements')
                            ->schema([
                                TextEntry::make('paid_at')->label('Paid'),
                                TextEntry::make('reference')->label('Reference'),
                                TextEntry::make('amount')->label('Amount'),
                                TextEntry::make('or_mapping')->label('OR Mapping'),
                            ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Financial Accommodation')
                    ->schema([
                        TextEntry::make('accommodation_summary.status')->label('Status'),
                        TextEntry::make('accommodation_summary.basis')->label('Basis'),
                        TextEntry::make('accommodation_summary.covered_amount')->label('Covered Amount'),
                        TextEntry::make('accommodation_summary.next_due')->label('Next Due'),
                        TextEntry::make('accommodation_summary.approved_effects')
                            ->label('Approved Effects')
                            ->listWithLineBreaks()
                            ->placeholder('No explicit finance effects recorded.'),
                    ])
                    ->columns(5),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('statement')
                ->label('View SOA')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => route('finance.statement', $this->finance['summary']['assessment_id'] ?? 0))
                ->openUrlInNewTab()
                ->disabled(fn (): bool => ($this->finance['available'] ?? false) !== true),
            Action::make('billingSlip')
                ->label('Billing Slip')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('finance.billing-slip', $this->finance['summary']['assessment_id'] ?? 0))
                ->openUrlInNewTab()
                ->disabled(fn (): bool => ($this->finance['available'] ?? false) !== true
                    || ! app(DecimalMoney::class)->greaterThanZero($this->finance['current_due_amount'] ?? '0.00')),
            Action::make('checkout')
                ->label('Pay Current Due')
                ->icon('heroicon-o-credit-card')
                ->action(fn (): RedirectResponse|Redirector|null => $this->startCheckout())
                ->disabled(fn (): bool => ($this->finance['available'] ?? false) !== true
                    || ! app(DecimalMoney::class)->greaterThanZero($this->finance['current_due_amount'] ?? '0.00')),
        ];
    }

    public function startCheckout(): RedirectResponse|Redirector|null
    {
        $actor = auth()->user();

        if ($actor === null || ($this->finance['available'] ?? false) !== true) {
            return null;
        }

        try {
            $session = app(CreatePaymentCheckoutSession::class)->create(
                actor: $actor,
                successUrl: route('filament.student.pages.finance', ['checkout' => 'success']),
                cancelUrl: route('filament.student.pages.finance', ['checkout' => 'cancelled']),
                metadata: ['source' => 'student_hub_finance'],
            );
        } catch (PaymentCheckoutException $exception) {
            $this->finance = app(FinanceEvidenceService::class)->studentFinance($actor);
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return null;
        } catch (Throwable) {
            Notification::make()
                ->title('Payment checkout is temporarily unavailable.')
                ->danger()
                ->send();

            return null;
        }

        return redirect()->away($session['checkout_url']);
    }
}
