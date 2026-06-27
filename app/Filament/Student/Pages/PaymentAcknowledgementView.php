<?php

namespace App\Filament\Student\Pages;

use App\Models\Payment;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentAcknowledgementView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Payment Acknowledgements';

    protected static ?string $title = 'Payment Acknowledgements';

    protected string $view = 'filament.student.pages.generic-table';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Payment::query()
                    ->whereHas('studentProfile', function (Builder $query) {
                        /** @var User $user */
                        $user = auth()->user();
                        $query->where('user_id', $user->id);
                    })
            )
            ->columns([
                TextColumn::make('payment_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money('PHP')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'verified' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('payment_method')
                    ->label('Method'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->emptyStateHeading('No payments found')
            ->emptyStateDescription('No payment records are available at this time.')
            ->emptyStateIcon('heroicon-o-receipt-refund');
    }
}
