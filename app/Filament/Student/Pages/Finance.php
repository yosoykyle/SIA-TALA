<?php

namespace App\Filament\Student\Pages;

use App\Actions\Finance\StudentTermAccountPresenter;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Finance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Finance';

    protected static ?string $title = 'Finance';

    /** @var array<string, mixed> */
    public array $finance = [];

    public function mount(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $this->finance = app(StudentTermAccountPresenter::class)->forUser($actor);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->state($this->finance['state'] ?? [])->components([
            Section::make('Current Term Account')
                ->description('The amount due today is separate from later Term obligations.')
                ->schema([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('current_due')->label('Due through as-of'),
                    TextEntry::make('remaining_balance')->label('Remaining Term balance'),
                    TextEntry::make('term'),
                    TextEntry::make('account_reference')->label('Account reference'),
                    TextEntry::make('assessment_version')->label('Assessment version'),
                    TextEntry::make('notice')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 3]),
            Section::make('Payment options')->schema([
                TextEntry::make('manual_payment')->label('Manual payment evidence')->columnSpanFull(),
                TextEntry::make('paymongo')->label('Online checkout')->columnSpanFull(),
            ]),
            Section::make('Obligations')->schema([
                RepeatableEntry::make('obligations')->hiddenLabel()->schema([
                    TextEntry::make('label'), TextEntry::make('purpose'), TextEntry::make('due_at')->label('Due'),
                    TextEntry::make('amount'), TextEntry::make('balance'), TextEntry::make('state')->badge(),
                ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
            ]),
            Section::make('Approved coverage')->schema([
                RepeatableEntry::make('coverages')->hiddenLabel()->schema([
                    TextEntry::make('category'), TextEntry::make('source'), TextEntry::make('amount'),
                    TextEntry::make('effective_date')->label('Effective'), TextEntry::make('state')->badge(),
                ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
            ])->collapsible()->collapsed(),
            Section::make('Verified payments')->schema([
                RepeatableEntry::make('payments')->hiddenLabel()->schema([
                    TextEntry::make('paid_at')->label('Paid'), TextEntry::make('reference'), TextEntry::make('channel'),
                    TextEntry::make('amount'), TextEntry::make('state')->badge(),
                ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
            ])->collapsible(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('statement')
                ->label('Statement of Account')
                ->icon('heroicon-o-document-text')
                ->url(fn (): string => route('finance.statement', data_get($this->finance, 'assessment.id', 0)))
                ->openUrlInNewTab()
                ->disabled(fn (): bool => ($this->finance['available'] ?? false) !== true),
            Action::make('latestAcknowledgement')
                ->label('Latest Payment Acknowledgment')
                ->icon('heroicon-o-check-badge')
                ->url(fn (): string => route('finance.payments.acknowledgement', data_get($this->finance, 'latest_payment.id', 0)))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($this->finance['latest_payment'] ?? null) instanceof Payment),
            Action::make('submitEvidence')
                ->label('Submit payment evidence')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(route('filament.student.pages.enrollment')),
        ];
    }
}
