<?php

namespace App\Filament\Student\Widgets;

use App\Models\Hold;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ActiveHoldsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Hold::query()
                    ->whereHas('studentProfile', function (Builder $query) {
                        /** @var User $user */
                        $user = auth()->user();
                        $query->where('user_id', $user->id);
                    })
                    ->where('status', Hold::StatusActive)
            )
            ->columns([
                TextColumn::make('hold_type')
                    ->label('Hold Type')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('blocking_level')
                    ->label('Effect')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('student_message')
                    ->label('Required Action')
                    ->wrap(),
            ])
            ->emptyStateHeading('No active holds')
            ->emptyStateDescription('You are clear from any blocks or deficiencies.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
