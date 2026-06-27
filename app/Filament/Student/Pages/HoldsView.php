<?php

namespace App\Filament\Student\Pages;

use App\Models\Hold;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HoldsView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Holds & Blockers';

    protected static ?string $title = 'Active Holds';

    protected string $view = 'filament.student.pages.generic-table';

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
                    ->label('Blocking Effect')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('student_message')
                    ->label('Required Action')
                    ->wrap(),
                TextColumn::make('effective_at')
                    ->label('Effective Date')
                    ->date(),
            ])
            ->emptyStateHeading('No active holds')
            ->emptyStateDescription('You are clear from any blocks or deficiencies.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
